<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Collector;

use Mha\HealthCheck\Config\HealthCheckConfig;
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
            'tables_without_primary_key' => $this->tablesWithoutPrimaryKey($connection),
            'changelog_tables' => $this->changelogTables($tables),
            'slow_query_evidence' => $this->slowQueryEvidence($connection),
            'integrity_checks' => $this->integrityChecks($connection),
            'connection_utilization' => $this->connectionUtilization($connection),
            'schema_metadata' => $this->schemaMetadata($connection),
            'remote_grant_evidence' => $this->remoteGrantEvidence($connection),
            'catalog' => $this->catalogFacts($connection),
            'growth_baseline' => 'Requires at least two saved scan snapshots.',
        ]];
    }

    /** @return array<string, mixed> */
    private function tablesWithoutPrimaryKey(AdapterInterface $connection): array
    {
        try {
            $rows = $connection->fetchAll('SELECT t.table_name, t.engine FROM information_schema.tables t LEFT JOIN information_schema.table_constraints c ON c.table_schema = t.table_schema AND c.table_name = t.table_name AND c.constraint_type = "PRIMARY KEY" WHERE t.table_schema = DATABASE() AND t.table_type = "BASE TABLE" AND c.constraint_name IS NULL');
            return ['status' => 'success', 'tables' => array_map(static fn(array $row): string => (string)$row['table_name'], $rows)];
        } catch (\Throwable $exception) {
            return ['status' => 'not_checked', 'reason' => 'Primary-key metadata could not be read.'];
        }
    }

    /** @param array<string, array<string, float>> $tables @return array<string, mixed> */
    private function changelogTables(array $tables): array
    {
        $changelog = [];
        foreach ($tables as $name => $table) {
            if (str_ends_with(strtolower($name), '_cl')) $changelog[$name] = (float)$table['total_size_mb'];
        }
        return ['status' => 'success', 'tables' => $changelog, 'largest_size_mb' => $changelog === [] ? 0.0 : max($changelog)];
    }

    /** @return array<string, mixed> */
    private function slowQueryEvidence(AdapterInterface $connection): array
    {
        try {
            $rows = $connection->fetchAll('SELECT DIGEST_TEXT, COUNT_STAR, ROUND(AVG_TIMER_WAIT / 1000000000000, 3) AS avg_seconds FROM performance_schema.events_statements_summary_by_digest WHERE COUNT_STAR > 0 ORDER BY AVG_TIMER_WAIT DESC LIMIT 5');
            return ['status' => 'success', 'digest_count' => count($rows), 'max_average_seconds' => $rows === [] ? 0.0 : max(array_map(static fn(array $row): float => (float)($row['avg_seconds'] ?? 0), $rows))];
        } catch (\Throwable $exception) {
            return ['status' => 'not_checked', 'reason' => 'Slow-query performance-schema evidence is unavailable.'];
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function integrityChecks(AdapterInterface $connection): array
    {
        $queries = [
            'foreign_keys' => 'SELECT k.table_name FROM information_schema.key_column_usage k LEFT JOIN information_schema.tables rt ON rt.table_schema = k.referenced_table_schema AND rt.table_name = k.referenced_table_name WHERE k.table_schema = DATABASE() AND k.referenced_table_name IS NOT NULL AND rt.table_name IS NULL LIMIT 100',
            'duplicate_sku' => 'SELECT sku FROM catalog_product_entity GROUP BY sku HAVING COUNT(*) > 1 LIMIT 100',
            'eav_linkage' => 'SELECT v.value_id FROM catalog_product_entity_varchar v LEFT JOIN catalog_product_entity e ON e.entity_id = v.entity_id LEFT JOIN eav_attribute a ON a.attribute_id = v.attribute_id WHERE e.entity_id IS NULL OR a.attribute_id IS NULL LIMIT 100',
            'attribute_metadata' => 'SELECT a.attribute_id FROM eav_attribute a LEFT JOIN eav_entity_type e ON e.entity_type_id = a.entity_type_id WHERE e.entity_type_id IS NULL LIMIT 100',
            'category_relationships' => 'SELECT ccp.category_id FROM catalog_category_product ccp LEFT JOIN catalog_category_entity c ON c.entity_id = ccp.category_id LEFT JOIN catalog_product_entity p ON p.entity_id = ccp.product_id WHERE c.entity_id IS NULL OR p.entity_id IS NULL LIMIT 100',
            'website_assignments' => 'SELECT cpw.product_id FROM catalog_product_website cpw LEFT JOIN store_website w ON w.website_id = cpw.website_id LEFT JOIN catalog_product_entity p ON p.entity_id = cpw.product_id WHERE w.website_id IS NULL OR p.entity_id IS NULL LIMIT 100',
            'quote_order' => 'SELECT so.entity_id FROM sales_order so LEFT JOIN quote q ON q.entity_id = so.quote_id WHERE so.quote_id IS NOT NULL AND so.quote_id > 0 AND q.entity_id IS NULL LIMIT 100',
            'msi_reservations' => 'SELECT r.reservation_id FROM inventory_reservation r LEFT JOIN inventory_stock s ON s.stock_id = r.stock_id WHERE s.stock_id IS NULL LIMIT 100',
        ];
        $results = [];
        foreach ($queries as $name => $query) {
            try {
                $rows = $connection->fetchAll($query);
                $results[$name] = ['status' => 'success', 'count' => count($rows)];
            } catch (\Throwable $exception) {
                $results[$name] = ['status' => 'not_checked', 'reason' => 'Required table or read-only metadata access is unavailable.'];
            }
        }
        return $results;
    }

    /** @return array<string, mixed> */
    private function connectionUtilization(AdapterInterface $connection): array
    {
        try {
            $status = $connection->fetchPairs('SHOW GLOBAL STATUS WHERE Variable_name IN ("Threads_connected", "Connection_errors_max_connections")');
            $variables = $connection->fetchPairs('SHOW GLOBAL VARIABLES WHERE Variable_name = "max_connections"');
            $current = (int)($status['Threads_connected'] ?? 0);
            $maximum = (int)($variables['max_connections'] ?? 0);
            return ['status' => 'success', 'current' => $current, 'maximum' => $maximum, 'utilization_percent' => $maximum > 0 ? round($current / $maximum * 100, 2) : null, 'connection_errors' => (int)($status['Connection_errors_max_connections'] ?? 0)];
        } catch (\Throwable $exception) {
            return ['status' => 'not_checked', 'reason' => 'Database connection status is unavailable.'];
        }
    }

    /** @return array<string, mixed> */
    private function schemaMetadata(AdapterInterface $connection): array
    {
        try {
            $rows = $connection->fetchAll('SELECT module, schema_version, data_version FROM setup_module');
            return ['status' => 'success', 'module_count' => count($rows)];
        } catch (\Throwable $exception) {
            return ['status' => 'not_checked', 'reason' => 'Magento setup-module metadata is unavailable.'];
        }
    }

    /** @return array<string, mixed> */
    private function remoteGrantEvidence(AdapterInterface $connection): array
    {
        try {
            $rows = $connection->fetchAll('SELECT user, host FROM mysql.user WHERE user = SUBSTRING_INDEX(CURRENT_USER(), "@", 1)');
            $hosts = array_values(array_unique(array_map(static fn(array $row): string => (string)($row['host'] ?? ''), $rows)));
            return ['status' => 'success', 'hosts' => $hosts];
        } catch (\Throwable $exception) {
            return ['status' => 'not_checked', 'reason' => 'mysql.user grant inspection is not available to the runtime account.'];
        }
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
