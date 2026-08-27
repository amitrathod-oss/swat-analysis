<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Test\Unit\Collector;

use Mha\HealthCheck\Collector\CronCollector;
use Mha\HealthCheck\Config\HealthCheckConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

class CronCollectorTest extends TestCase
{
    public function testCollectReturnsCronCountsAndLimitedErrorSamples(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $config = $this->createMock(HealthCheckConfig::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->with('cron_schedule')->willReturn('cron_schedule');
        $connection->method('quoteIdentifier')->with('cron_schedule')->willReturn('`cron_schedule`');
        $config->method('getPositiveInt')->willReturnMap([
            ['scan.cron_window_hours', 24, 24],
            ['scan.cron_stale_running_minutes', 60, 60],
            ['scan.cron_error_sample_limit', 20, 20],
        ]);
        $connection->method('fetchOne')->willReturn('2');
        $connection->method('fetchPairs')->willReturnOnConsecutiveCalls(
            ['error' => '3', 'pending' => '6'],
            ['catalog_reindex_all' => '3']
        );
        $connection->method('fetchAll')->willReturn([[
            'job_code' => 'catalog_reindex_all',
            'message' => 'Example failure',
            'executed_at' => '2026-08-24 10:00:00',
        ]]);

        $result = (new CronCollector($resourceConnection, $config))->collect();

        self::assertSame(3, $result['metrics']['status_counts']['error']);
        self::assertSame(0, $result['metrics']['status_counts']['missed']);
        self::assertSame(2, $result['metrics']['stale_running_count']);
        self::assertSame(3, $result['metrics']['top_failing_job_codes']['catalog_reindex_all']);
        self::assertSame('Example failure', $result['metrics']['recent_errors'][0]['message']);
    }
}
