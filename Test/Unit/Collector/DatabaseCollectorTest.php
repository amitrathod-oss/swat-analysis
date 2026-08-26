<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Test\Unit\Collector;

use Sigma\HealthCheck\Collector\DatabaseCollector;
use Sigma\HealthCheck\Config\HealthCheckConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

class DatabaseCollectorTest extends TestCase
{
    public function testCollectReturnsReadOnlyDatabaseMetrics(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $config = $this->createMock(HealthCheckConfig::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $config->method('getPositiveInt')->with('scan.database_row_limit', 500)->willReturn(500);
        $connection->method('fetchOne')->with('SELECT VERSION()')->willReturn('10.6.20-MariaDB');
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [[
                'table_name' => 'sales_order',
                'estimated_rows' => '12',
                'data_size_mb' => '10.50',
                'index_size_mb' => '2.00',
                'total_size_mb' => '12.50',
            ]],
            [[
                'attribute_id' => '93',
                'attribute_code' => 'brand',
                'option_count' => '101',
            ]],
            [[
                'trigger_name' => 'audit_sales_order',
                'table_name' => 'sales_order',
                'event' => 'INSERT',
                'timing' => 'AFTER',
                'statement_summary' => "BEGIN\nSELECT 1; END",
            ]]
        );
        $connection->method('fetchPairs')->willReturn([
            'Innodb_buffer_pool_pages_data' => '80',
            'Innodb_buffer_pool_pages_total' => '100',
        ]);

        $result = (new DatabaseCollector($resourceConnection, $config))->collect();

        self::assertSame('10.6.20-MariaDB', $result['metrics']['version']);
        self::assertSame(12.5, $result['metrics']['tables']['sales_order']['total_size_mb']);
        self::assertSame(101, $result['metrics']['attribute_options']['brand']['option_count']);
        self::assertSame(80.0, $result['metrics']['buffer_pool']['utilization_percent']);
        self::assertSame(1, $result['metrics']['trigger_count']);
        self::assertSame('BEGIN SELECT 1; END', $result['metrics']['triggers']['audit_sales_order']['statement_summary']);
    }
}
