<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Test\Unit\Model;

use Mha\HealthCheck\Collector\CollectorInterface;
use Mha\HealthCheck\Finding\FindingFactory;
use Mha\HealthCheck\Model\ScanRunner;
use Mha\HealthCheck\Rule\RuleEngine;
use Mha\HealthCheck\Rule\RuleLoader;
use Mha\HealthCheck\Security\SecretSanitizer;
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
