<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Model;

use Sigma\HealthCheck\Collector\CollectorInterface;
use Sigma\HealthCheck\Finding\FindingFactory;
use Sigma\HealthCheck\Rule\RuleEngine;
use Sigma\HealthCheck\Rule\RuleLoader;
use Sigma\HealthCheck\Security\SecretSanitizer;

class ScanRunner
{
    /**
     * @var CollectorInterface[]
     */
    private array $collectors;
    private RuleLoader $ruleLoader;
    private RuleEngine $ruleEngine;
    private FindingFactory $findingFactory;
    private SecretSanitizer $secretSanitizer;

    /**
     * @param CollectorInterface[] $collectors
     */
    public function __construct(
        RuleLoader $ruleLoader,
        RuleEngine $ruleEngine,
        FindingFactory $findingFactory,
        SecretSanitizer $secretSanitizer,
        array $collectors = []
    ) {
        $this->ruleLoader = $ruleLoader;
        $this->ruleEngine = $ruleEngine;
        $this->findingFactory = $findingFactory;
        $this->secretSanitizer = $secretSanitizer;
        $this->collectors = $collectors;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function run(array $context = []): ScanResult
    {
        $scanResult = new ScanResult(bin2hex(random_bytes(16)));
        $scanResult->setHistoryEnabled(!(bool)($context['no_history'] ?? false));
        $scanResult->setContext($context);
        $metrics = [];

        foreach ($this->collectors as $collector) {
            $collectorCode = get_class($collector);
            $requestedOnly = $context['only'] ?? [];
            $requestedSkip = $context['skip'] ?? [];
            $started = microtime(true);
            try {
                $collectorCode = $collector->getCode();
                if (is_array($requestedOnly) && $requestedOnly !== [] && !in_array($collectorCode, $requestedOnly, true)) {
                    continue;
                }
                if (is_array($requestedSkip) && in_array($collectorCode, $requestedSkip, true)) {
                    continue;
                }
                if ($context !== [] && !$collector->isSupported($context)) {
                    $scanResult->addCollectorResult($collectorCode, [
                        'collector' => $collectorCode,
                        'status' => 'not_applicable',
                        'collected_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                        'duration_ms' => round((microtime(true) - $started) * 1000, 2),
                        'metrics' => [],
                        'meta' => ['reason' => 'Collector is not supported by the scan context.'],
                    ]);
                    $metrics['collector_status'][$collectorCode] = ['status' => 'not_applicable'];
                    continue;
                }
                $collectorResult = $collector->collect($context);
                $collectorResult += [
                    'collector' => $collectorCode,
                    'status' => 'success',
                    'collected_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'duration_ms' => round((microtime(true) - $started) * 1000, 2),
                    'metrics' => [],
                ];
                $collectorResult = $this->secretSanitizer->sanitize($collectorResult);
                $scanResult->addCollectorResult($collectorCode, $collectorResult);
                $metrics['collector_status'][$collectorCode] = [
                    'status' => (string)($collectorResult['status'] ?? 'success'),
                ];
                if (($collectorResult['status'] ?? null) === 'success' && is_array($collectorResult['metrics'] ?? null)) {
                    $metrics[$collectorCode] = $collectorResult['metrics'];
                }
            } catch (\Throwable $exception) {
                $scanResult->addCollectorResult($collectorCode, [
                    'collector' => $collectorCode,
                    'status' => 'unavailable',
                    'message' => $this->secretSanitizer->sanitize($exception->getMessage()),
                    'collected_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'duration_ms' => round((microtime(true) - $started) * 1000, 2),
                    'metrics' => [],
                ]);
            }
        }

        try {
            foreach ($this->ruleEngine->evaluate($metrics, $this->ruleLoader->load(), new \DateTimeImmutable()) as $finding) {
                $scanResult->addFinding($this->findingFactory->create($this->secretSanitizer->sanitize($finding->toArray())));
            }
        } catch (\Throwable $exception) {
            $scanResult->addScanError('rule_engine', (string)$this->secretSanitizer->sanitize($exception->getMessage()));
        }

        $scanResult->complete();
        return $scanResult;
    }
}
