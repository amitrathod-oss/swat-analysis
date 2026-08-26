<?php
declare(strict_types=1);

namespace Asiamarket\HealthCheck\Test\Unit\Collector;

use Asiamarket\HealthCheck\Collector\RedisCollector;
use Asiamarket\HealthCheck\Config\HealthCheckConfig;
use Asiamarket\HealthCheck\Service\RedisClientFactory;
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
