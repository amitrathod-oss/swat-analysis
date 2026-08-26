<?php
declare(strict_types=1);

namespace Asiamarket\HealthCheck\Test\Unit\Collector;

use Asiamarket\HealthCheck\Collector\OpenSearchCollector;
use Asiamarket\HealthCheck\Config\HealthCheckConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\TestCase;

class OpenSearchCollectorTest extends TestCase
{
    public function testCollectIsNotApplicableWhenSearchHostIsNotConfigured(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('');

        $result = (new OpenSearchCollector(
            $scopeConfig,
            $this->createMock(CurlFactory::class),
            $this->createMock(HealthCheckConfig::class)
        ))->collect();

        self::assertSame('not_applicable', $result['status']);
        self::assertSame([], $result['metrics']);
    }
}
