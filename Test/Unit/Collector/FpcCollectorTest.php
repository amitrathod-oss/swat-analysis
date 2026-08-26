<?php
declare(strict_types=1);

namespace Asiamarket\HealthCheck\Test\Unit\Collector;

use Asiamarket\HealthCheck\Collector\FpcCollector;
use Asiamarket\HealthCheck\Config\HealthCheckConfig;
use Asiamarket\HealthCheck\Service\RepresentativeUrlProvider;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\TestCase;

class FpcCollectorTest extends TestCase
{
    public function testCollectReportsCacheStateWithoutRequestingEmptyUrls(): void
    {
        $cacheTypeList = $this->createMock(TypeListInterface::class);
        $config = $this->createMock(HealthCheckConfig::class);
        $cacheTypeList->method('getTypes')->willReturn([]);
        $config->method('getResolvedUrls')->with('fpc.urls', [])->willReturn([]);

        $result = (new FpcCollector(
            $cacheTypeList,
            $this->createMock(CurlFactory::class),
            $config,
            $this->createMock(RepresentativeUrlProvider::class)
        ))->collect();

        self::assertFalse($result['metrics']['enabled']);
        self::assertSame(0, $result['metrics']['tested_urls']);
        self::assertArrayNotHasKey('hit_rate_percent', $result['metrics']);
    }
}
