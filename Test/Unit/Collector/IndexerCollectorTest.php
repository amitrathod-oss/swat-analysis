<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Test\Unit\Collector;

use Sigma\HealthCheck\Collector\IndexerCollector;
use Sigma\HealthCheck\Config\HealthCheckConfig;
use Magento\Framework\Indexer\ConfigInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use PHPUnit\Framework\TestCase;

class IndexerCollectorTest extends TestCase
{
    public function testCollectIdentifiesInvalidAndUnexpectedRealtimeIndexers(): void
    {
        $indexerConfig = $this->createMock(ConfigInterface::class);
        $indexerRegistry = $this->createMock(IndexerRegistry::class);
        $config = $this->createMock(HealthCheckConfig::class);
        $indexer = $this->createMock(IndexerInterface::class);
        $indexerConfig->method('getIndexers')->willReturn(['catalogsearch_fulltext' => []]);
        $indexerRegistry->method('get')->with('catalogsearch_fulltext')->willReturn($indexer);
        $config->method('getStringList')->with('indexers.expected_schedule')->willReturn(['catalogsearch_fulltext']);
        $indexer->method('isScheduled')->willReturn(false);
        $indexer->method('getTitle')->willReturn('Catalog Search');
        $indexer->method('getStatus')->willReturn('invalid');
        $indexer->method('getLatestUpdated')->willReturn('2026-08-24 10:00:00');

        $result = (new IndexerCollector($indexerConfig, $indexerRegistry, $config))->collect();

        self::assertSame('invalid', $result['metrics']['indexers']['catalogsearch_fulltext']['status']);
        self::assertSame('realtime', $result['metrics']['indexers']['catalogsearch_fulltext']['mode']);
        self::assertSame(1, $result['metrics']['unexpected_realtime_count']);
    }
}
