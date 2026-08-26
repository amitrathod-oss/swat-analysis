<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Test\Unit\Model;

use Sigma\HealthCheck\Collector\CollectorInterface;
use Sigma\HealthCheck\Finding\FindingFactory;
use Sigma\HealthCheck\Model\ScanRunner;
use Sigma\HealthCheck\Rule\RuleEngine;
use Sigma\HealthCheck\Rule\RuleLoader;
use Sigma\HealthCheck\Security\SecretSanitizer;
use PHPUnit\Framework\TestCase;

class ScanRunnerTest extends TestCase
{
    public function testRunContinuesWhenCollectorFailsAndSanitizesFailureMessage(): void
    {
        $collector = $this->createMock(CollectorInterface::class);
        $collector->method('getCode')->willReturn('broken_collector');
        $collector->method('collect')->willThrowException(new \RuntimeException('password=database-secret'));

        $ruleLoader = $this->createMock(RuleLoader::class);
        $ruleLoader->method('load')->willReturn([]);
        $ruleEngine = $this->createMock(RuleEngine::class);
        $ruleEngine->method('evaluate')->willReturn([]);

        $result = (new ScanRunner(
            $ruleLoader,
            $ruleEngine,
            new FindingFactory(),
            new SecretSanitizer(),
            [$collector]
        ))->run()->toArray();

        self::assertSame('unavailable', $result['collectors']['broken_collector']['status']);
        self::assertStringNotContainsString('database-secret', $result['collectors']['broken_collector']['message']);
        self::assertSame([], $result['findings']);
    }
}
