<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Collector;

use Mha\HealthCheck\Config\HealthCheckConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

class DatabaseCollector implements CollectorInterface
{
    private ResourceConnection $resourceConnection;
    private HealthCheckConfig $config;

    public function __construct(ResourceConnection $resourceConnection, HealthCheckConfig $config)
    {
        $this->resourceConnection = $resourceConnection;
        $this->config = $config;
    }

    public function getCode(): string
    {
        return 'database';
    }

    public function isSupported(array $context = []): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(array $context = []): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tables = $this->collectTables($connection);
        $attributeOptions = $this->collectAttributeOptions($connection);
        $bufferPool = $this->collectBufferPool($connection);
        $triggers = $this->collectTriggers($connection);

        return [
            'metrics' => [
                'version' => (string)$connection->fetchOne('SELECT VERSION()'),
                'tables' => $tables,
                'attribute_options' => $attributeOptions,
                'buffer_pool' => $bufferPool,
                'trigger_count' => count($triggers),
                'triggers' => $triggers,
            ],
        ];
    }

    /**
     * @return array<string, array<string, int|float>>
     */
    private function collectTables(AdapterInterface $connection): array
    {
        $limit = $this->config->getPositiveInt('scan.database_row_limit', 500);
        $rows = $connection->fetchAll(
            'SELECT TABLE_NAME AS table_name, TABLE_ROWS AS estimated_rows, '
            . 'ROUND(DATA_LENGTH / 1024 / 1024, 2) AS data_size_mb, '
            . 'ROUND(INDEX_LENGTH / 1024 / 1024, 2) AS index_size_mb, '
            . 'ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS total_size_mb '
            . 'FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE" '
            . 'ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC '
            . 'LIMIT ' . $limit
        );
        $tables = [];
        foreach ($rows as $row) {
            $tables[(string)$row['table_name']] = [
                'estimated_rows' => (int)$row['estimated_rows'],
                'data_size_mb' => (float)$row['data_size_mb'],
                'index_size_mb' => (float)$row['index_size_mb'],
                'total_size_mb' => (float)$row['total_size_mb'],
            ];
        }

        return $tables;
    }

    /**
     * @return array<string, array<string, int|string>>
     */
    private function collectAttributeOptions(AdapterInterface $connection): array
    {
        $limit = $this->config->getPositiveInt('scan.database_row_limit', 500);
        $rows = $connection->fetchAll(
            'SELECT ea.attribute_id, ea.attribute_code, COUNT(eao.option_id) AS option_count '
            . 'FROM eav_attribute AS ea '
            . 'INNER JOIN eav_entity_type AS eet ON eet.entity_type_id = ea.entity_type_id '
            . 'LEFT JOIN eav_attribute_option AS eao ON eao.attribute_id = ea.attribute_id '
            . 'WHERE eet.entity_type_code = "catalog_product" '
            . 'GROUP BY ea.attribute_id, ea.attribute_code '
            . 'ORDER BY option_count DESC '
            . 'LIMIT ' . $limit
        );
        $attributes = [];
        foreach ($rows as $row) {
            $attributeCode = (string)$row['attribute_code'];
            $attributes[$attributeCode] = [
                'attribute_id' => (int)$row['attribute_id'],
                'option_count' => (int)$row['option_count'],
            ];
        }

        return $attributes;
    }

    /**
     * @return array<string, float|int|null>
     */
    private function collectBufferPool(AdapterInterface $connection): array
    {
        $status = $connection->fetchPairs(
            'SHOW GLOBAL STATUS WHERE Variable_name IN ('
            . '"Innodb_buffer_pool_pages_data", "Innodb_buffer_pool_pages_total")'
        );
        $pagesData = (int)($status['Innodb_buffer_pool_pages_data'] ?? 0);
        $pagesTotal = (int)($status['Innodb_buffer_pool_pages_total'] ?? 0);

        return [
            'pages_data' => $pagesData,
            'pages_total' => $pagesTotal,
            'utilization_percent' => $pagesTotal > 0 ? round(($pagesData / $pagesTotal) * 100, 2) : null,
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function collectTriggers(AdapterInterface $connection): array
    {
        $rows = $connection->fetchAll(
            'SELECT TRIGGER_NAME AS trigger_name, EVENT_OBJECT_TABLE AS table_name, '
            . 'EVENT_MANIPULATION AS event, ACTION_TIMING AS timing, '
            . 'LEFT(ACTION_STATEMENT, 500) AS statement_summary '
            . 'FROM information_schema.TRIGGERS '
            . 'WHERE TRIGGER_SCHEMA = DATABASE() '
            . 'ORDER BY TRIGGER_NAME'
        );
        $triggers = [];
        foreach ($rows as $row) {
            $triggers[(string)$row['trigger_name']] = [
                'table' => (string)$row['table_name'],
                'event' => (string)$row['event'],
                'timing' => (string)$row['timing'],
                'statement_summary' => trim((string)preg_replace('/\s+/', ' ', (string)$row['statement_summary'])),
            ];
        }

        return $triggers;
    }
}
