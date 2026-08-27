<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Model;

use Mha\HealthCheck\Finding\Finding;
use DateTimeImmutable;
use DateTimeInterface;

class ScanResult
{
    private string $scanId;
    private DateTimeImmutable $startedAt;
    private ?DateTimeImmutable $completedAt = null;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $collectors = [];

    /**
     * @var Finding[]
     */
    private array $findings = [];

    /**
     * @var array<string, string>
     */
    private array $scanErrors = [];
    private bool $historyEnabled = true;
    /** @var array<string, mixed> */
    private array $context = [];

    public function __construct(string $scanId, ?DateTimeImmutable $startedAt = null)
    {
        $this->scanId = $scanId;
        $this->startedAt = $startedAt ?? new DateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $result
     */
    public function addCollectorResult(string $collectorCode, array $result): void
    {
        $this->collectors[$collectorCode] = $result;
    }

    public function addFinding(Finding $finding): void
    {
        $this->findings[] = $finding;
    }

    public function addScanError(string $component, string $message): void
    {
        $this->scanErrors[$component] = $message;
    }

    public function complete(?DateTimeImmutable $completedAt = null): void
    {
        $this->completedAt = $completedAt ?? new DateTimeImmutable();
    }

    public function setHistoryEnabled(bool $enabled): void
    {
        $this->historyEnabled = $enabled;
    }

    public function isHistoryEnabled(): bool
    {
        return $this->historyEnabled;
    }

    /** @param array<string, mixed> $context */
    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $completedAt = $this->completedAt ?? new DateTimeImmutable();
        $severityCounts = [
            'severe' => 0,
            'high' => 0,
            'elevated' => 0,
            'medium' => 0,
            'low' => 0,
            'info' => 0,
        ];
        $findings = [];

        foreach ($this->findings as $finding) {
            $findingData = $finding->toArray();
            $severity = strtolower($finding->getRiskLevel());
            if (array_key_exists($severity, $severityCounts)) {
                $severityCounts[$severity]++;
            }
            $findings[] = $findingData;
        }

        return [
            'schema_version' => '1.0',
            'scan_id' => $this->scanId,
            'started_at' => $this->startedAt->format(DateTimeInterface::ATOM),
            'completed_at' => $completedAt->format(DateTimeInterface::ATOM),
            'duration_seconds' => max(0, $completedAt->getTimestamp() - $this->startedAt->getTimestamp()),
            'application' => [
                'platform' => 'Magento Open Source',
                'version' => null,
            ],
            'health_score' => null,
            'severity_counts' => $severityCounts,
            'collectors' => $this->collectors,
            'findings' => $findings,
            'scan_errors' => $this->scanErrors,
        ];
    }
}
