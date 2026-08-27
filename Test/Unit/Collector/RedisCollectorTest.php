<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Test\Unit\Collector;

use Mha\HealthCheck\Collector\RedisCollector;
use Mha\HealthCheck\Config\HealthCheckConfig;
use Mha\HealthCheck\Service\RedisClientFactory;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

class RedisCollectorTest extends TestCase
{
    public function testCollectIsNotApplicableWhenRedisIsNotConfigured(): void
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturn([]);

        $result = (new RedisCollector(
            $deploymentConfig,
            $this->createMock(RedisClientFactory::class),
            $this->createMock(HealthCheckConfig::class)
        ))->collect();

        self::assertSame('not_applicable', $result['status']);
        self::assertSame([], $result['metrics']);
    }
}
