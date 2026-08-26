<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Collector;

use Sigma\HealthCheck\Config\HealthCheckConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/** Additional read-only database observations that are safe to sample. */
class DatabaseAdvancedCollector implements CollectorInterface
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
        return 'database_advanced';
    }

    public function isSupported(array $context = []): bool
    {
        return true;
    }

    public function collect(array $context = []): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tables = $this->tableFacts($connection);
        $status = $this->statusFacts($connection);
        return ['metrics' => [
            'total_size_mb' => array_sum(array_map(static fn(array $row): float => (float)$row['total_size_mb'], $tables)),
            'table_groups' => $this->groups($tables),
            'deadlocks' => (int)($status['Innodb_deadlocks'] ?? 0),
            'row_lock_waits' => (int)($status['Innodb_row_lock_current_waits'] ?? 0),
            'long_running_queries' => $this->longRunningQueries($connection),
            'catalog' => $this->catalogFacts($connection),
            'growth_baseline' => 'Requires at least two saved scan snapshots.',
        ]];
    }

    /** @return array<string, array<string, float>> */
    private function tableFacts(AdapterInterface $connection): array
    {
        $rows = $connection->fetchAll(
            'SELECT TABLE_NAME AS table_name, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS total_size_mb '
            . 'FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE"'
        );
        $facts = [];
        foreach ($rows as $row) {
            $facts[(string)$row['table_name']] = ['total_size_mb' => (float)$row['total_size_mb']];
        }
        return $facts;
    }

    /** @return array<string, int|string> */
    private function statusFacts(AdapterInterface $connection): array
    {
        return $connection->fetchPairs('SHOW GLOBAL STATUS WHERE Variable_name IN ("Innodb_deadlocks", "Innodb_row_lock_current_waits")');
    }

    /** @param array<string, array<string, float>> $tables @return array<string, float> */
    private function groups(array $tables): array
    {
        $groups = ['cron' => 0.0, 'report' => 0.0, 'log' => 0.0, 'eav' => 0.0];
        foreach ($tables as $name => $row) {
            $lower = strtolower($name);
            foreach (array_keys($groups) as $group) {
                if (str_contains($lower, $group)) {
                    $groups[$group] += $row['total_size_mb'];
                }
            }
            if (str_starts_with($lower, 'catalog_product_entity_') || str_starts_with($lower, 'catalog_category_entity_')) {
                $groups['eav'] += $row['total_size_mb'];
            }
        }
        return $groups;
    }

    /** @return array<string, int|float> */
    private function longRunningQueries(AdapterInterface $connection): array
    {
        try {
            $rows = $connection->fetchAll('SHOW FULL PROCESSLIST');
            $threshold = $this->config->getPositiveInt('scan.long_query_seconds', 10);
            $count = 0;
            $max = 0;
            foreach ($rows as $row) {
                $time = (int)($row['Time'] ?? $row['TIME'] ?? 0);
                $max = max($max, $time);
                if ($time >= $threshold && strtoupper((string)($row['Command'] ?? '')) !== 'SLEEP') {
                    $count++;
                }
            }
            return ['count' => $count, 'max_seconds' => $max, 'threshold_seconds' => $threshold];
        } catch (\Throwable $exception) {
            return ['count' => 0, 'max_seconds' => 0, 'threshold_seconds' => 0, 'status' => 'unavailable'];
        }
    }

    /** @return array<string, int> */
    private function catalogFacts(AdapterInterface $connection): array
    {
        $facts = ['products' => 0, 'categories' => 0, 'configurable_products' => 0, 'max_category_depth' => 0];
        $queries = [
            'products' => 'SELECT COUNT(*) FROM catalog_product_entity',
            'categories' => 'SELECT COUNT(*) FROM catalog_category_entity',
            'configurable_products' => 'SELECT COUNT(DISTINCT parent_id) FROM catalog_product_super_link',
            'max_category_depth' => 'SELECT COALESCE(MAX(LENGTH(path) - LENGTH(REPLACE(path, "/", ""))), 0) FROM catalog_category_entity',
        ];
        foreach ($queries as $key => $query) {
            try {
                $facts[$key] = (int)$connection->fetchOne($query);
            } catch (\Throwable $exception) {
                $facts[$key] = 0;
            }
        }
        return $facts;
    }
}
