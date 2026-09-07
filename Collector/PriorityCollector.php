<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Collector;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\StoreManagerInterface;
use Mha\HealthCheck\Config\HealthCheckConfig;

/** Read-only evidence for the CSV rules that lack a dedicated collector. */
class PriorityCollector implements CollectorInterface
{
    public function __construct(
        private ResourceConnection $resource,
        private DirectoryList $directoryList,
        private DeploymentConfig $deployment,
        private ScopeConfigInterface $scope,
        private StoreManagerInterface $stores,
        private CurlFactory $curlFactory,
        private HealthCheckConfig $config,
        private \Magento\Framework\App\ProductMetadataInterface $productMetadata
    ) {}

    public function getCode(): string { return 'priority'; }
    public function isSupported(array $context = []): bool { return true; }

    public function collect(array $context = []): array
    {
        $probes = [
            'https' => fn() => $this->https(),
            'security_patches' => fn() => $this->patches(),
            'two_factor' => fn() => $this->twoFactor(),
            'secure_cookies' => fn() => $this->secureCookies(),
            'auto_increment' => fn() => $this->autoIncrement(),
            'public_backups' => fn() => $this->files(true),
            'admin_path' => fn() => $this->observation(!in_array(strtolower(trim((string)$this->deployment->get('backend/frontName', ''), '/')), ['', 'admin'], true), 'A custom admin frontName is required.'),
            'changelog' => fn() => $this->changelog(),
            'foreign_keys' => fn() => $this->foreignKeys(),
            'eav' => fn() => $this->eav(),
            'duplicate_sku' => fn() => $this->duplicateSku(),
            'log_size' => fn() => $this->files(false),
            'table_bloat' => fn() => $this->tableBloat(),
            'queue' => fn() => $this->queue(),
            'fpc_engine' => fn() => $this->fpcEngine(),
            'cron_recent' => fn() => $this->cronRecent(),
        ];
        $metrics = [];
        foreach ($probes as $key => $probe) {
            try {
                $metrics[$key] = $probe();
            } catch (\Throwable $exception) {
                $metrics[$key] = $this->observation(null, 'Required read-only evidence is unavailable for ' . $key . '.');
            }
        }
        return ['metrics' => $metrics];
    }

    private function observation(?bool $compliant, string $reason, array $details = []): array
    {
        return ['compliant' => $compliant, 'reason' => $reason, 'details' => $details];
    }

    private function table(string $name): string
    {
        return $this->resource->getConnection()->quoteIdentifier($this->resource->getTableName($name));
    }

    private function autoIncrement(): array
    {
        $rows = $this->resource->getConnection()->fetchAll("SELECT t.TABLE_NAME, t.AUTO_INCREMENT, c.DATA_TYPE, c.COLUMN_TYPE FROM information_schema.TABLES t JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME WHERE t.TABLE_SCHEMA=DATABASE() AND c.EXTRA LIKE '%auto_increment%'");
        $limits = ['tinyint' => 8, 'smallint' => 16, 'mediumint' => 24, 'int' => 32, 'bigint' => 64];
        $risk = [];
        foreach ($rows as $row) {
            $bits = $limits[strtolower($row['DATA_TYPE'])] ?? null;
            if ($bits === null || !is_numeric($row['AUTO_INCREMENT'])) return $this->observation(null, 'Auto-increment capacity metadata is incomplete.');
            $maximum = 2 ** ($bits - (stripos($row['COLUMN_TYPE'], 'unsigned') === false ? 1 : 0)) - 1;
            $percent = (float)$row['AUTO_INCREMENT'] / $maximum * 100;
            if ($percent >= 80) $risk[$row['TABLE_NAME']] = round($percent, 2);
        }
        return $this->observation($risk === [], 'Auto-increment usage must remain below 80%.', ['at_risk_tables_percent' => $risk]);
    }

    private function changelog(): array
    {
        $db = $this->resource->getConnection();
        $names = $db->fetchCol('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE="BASE TABLE"');
        $versions = $db->fetchPairs('SELECT view_id, version_id FROM ' . $this->table('mview_state'));
        $processed = [];
        foreach ($versions as $view => $version) $processed[$this->resource->getTableName($view . '_cl')] = (int)$version;
        $counts = [];
        foreach ($names as $name) {
            if (!str_ends_with((string)$name, '_cl')) continue;
            // Bound the scan at the failure threshold rather than counting an unbounded backlog.
            $count = (int)$db->fetchOne('SELECT COUNT(*) FROM (SELECT 1 FROM ' . $db->quoteIdentifier($name) . ' WHERE version_id > ' . ($processed[$name] ?? 0) . ' LIMIT 100000) sampled');
            if ($count >= 100000) $counts[$name] = $count;
        }
        return $this->observation($counts === [], 'Changelog tables must contain fewer than 100,000 unprocessed rows (bounded count after the mview state version).', ['large_changelogs' => $counts]);
    }

    private function foreignKeys(): array
    {
        $db = $this->resource->getConnection();
        $rows = $db->fetchAll('SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION');
        $groups = [];
        foreach ($rows as $row) $groups[$row['TABLE_NAME'] . ':' . $row['CONSTRAINT_NAME']][] = $row;
        $invalid = [];
        foreach ($groups as $name => $columns) {
            $join = []; $nonNull = [];
            foreach ($columns as $column) {
                $child = 'c.' . $db->quoteIdentifier($column['COLUMN_NAME']);
                $join[] = $child . '=p.' . $db->quoteIdentifier($column['REFERENCED_COLUMN_NAME']);
                $nonNull[] = $child . ' IS NOT NULL';
            }
            $first = $columns[0];
            $query = 'SELECT 1 FROM ' . $db->quoteIdentifier($first['TABLE_NAME']) . ' c WHERE ' . implode(' AND ', $nonNull)
                . ' AND NOT EXISTS (SELECT 1 FROM ' . $db->quoteIdentifier($first['REFERENCED_TABLE_SCHEMA']) . '.' . $db->quoteIdentifier($first['REFERENCED_TABLE_NAME']) . ' p WHERE ' . implode(' AND ', $join) . ') LIMIT 1';
            if ($db->fetchOne($query)) $invalid[] = $name;
        }
        return $this->observation($invalid === [], 'Declared foreign keys were checked for orphan child records.', ['violations' => $invalid, 'constraints_checked' => count($groups)]);
    }

    private function eav(): array
    {
        $db = $this->resource->getConnection();
        $invalid = []; $checked = 0;
        foreach (['catalog_product_entity', 'catalog_category_entity', 'customer_entity', 'customer_address_entity'] as $entity) {
            $parent = $this->table($entity);
            $schema = $db->describeTable($this->resource->getTableName($entity));
            $link = isset($schema['row_id']) ? 'row_id' : 'entity_id';
            foreach (['int', 'varchar', 'text', 'decimal', 'datetime'] as $type) {
                $name = $entity . '_' . $type;
                if (!$db->isTableExists($this->resource->getTableName($name))) continue;
                $child = $this->table($name);
                $query = 'SELECT 1 FROM ' . $child . ' v LEFT JOIN ' . $parent . ' p ON p.' . $link . '=v.' . $link . ' LEFT JOIN ' . $this->table('eav_attribute') . ' a ON a.attribute_id=v.attribute_id WHERE p.' . $link . ' IS NULL OR a.attribute_id IS NULL LIMIT 1';
                if ($db->fetchOne($query)) $invalid[] = $name;
                $checked++;
            }
        }
        return $this->observation($checked === 0 ? null : $invalid === [], 'EAV value tables were checked for missing parent entities or attributes.', ['violations' => $invalid, 'tables_checked' => $checked]);
    }

    private function duplicateSku(): array
    {
        $found = $this->resource->getConnection()->fetchOne('SELECT 1 FROM ' . $this->table('catalog_product_entity') . ' GROUP BY sku HAVING COUNT(*) > 1 LIMIT 1');
        return $this->observation(!$found, 'Product SKUs must be unique.');
    }

    private function tableBloat(): array
    {
        $rows = $this->resource->getConnection()->fetchAll('SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH+INDEX_LENGTH AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE="BASE TABLE" AND (DATA_LENGTH+INDEX_LENGTH > 10737418240 OR TABLE_ROWS > 10000000)');
        return $this->observation($rows === [], 'Tables must not exceed 10 GiB or 10 million estimated rows.', ['oversized_tables' => $rows]);
    }

    private function files(bool $backups): array
    {
        $root = rtrim($this->directoryList->getRoot(), '/');
        $matches = []; $count = 0;
        foreach ($backups ? ['pub', 'var'] : ['var/log'] as $relative) {
            $path = $root . '/' . $relative;
            if (!is_dir($path)) continue;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (++$count > 20000) return $this->observation($matches === [] ? null : false, 'Filesystem sample reached its 20,000-entry limit.', ['matches' => $matches]);
                if (!$file->isFile() || $file->isLink()) continue;
                $name = substr($file->getPathname(), strlen($root) + 1);
                if ($backups) {
                    if (preg_match('/\.(sql(?:\.gz)?|tar(?:\.gz)?|tgz|zip|bak|log)$/i', $name)
                        && ($relative === 'pub' || ($file->getPerms() & 0077) !== 0)) $matches[] = $name;
                } elseif ($file->getSize() >= 100 * 1024 ** 2) $matches[] = $name;
            }
        }
        return $this->observation($matches === [], $backups ? 'Backups/logs must stay outside pub and have restricted permissions.' : 'Observed log files must remain below 100 MiB; growth and rotation require historical/host evidence.', ['matches' => $matches]);
    }

    private function patches(): array
    {
        // A patch file existing on disk does not prove that it was applied.
        $evidence = $this->config->get('priority.security_patch_evidence', []);
        if (!is_array($evidence) || empty($evidence['verified_at']) || !isset($evidence['missing_patch_ids']) || !is_array($evidence['missing_patch_ids'])) return $this->observation(null, 'Supply verified security_patch_evidence after comparing this release with Adobe bulletins.');
        if (($evidence['magento_version'] ?? '') !== $this->productMetadata->getVersion()) return $this->observation(null, 'Security patch evidence must match the installed Magento version.');
        $verified = strtotime((string)$evidence['verified_at']);
        if ($verified === false || $verified > time() || $verified < time() - 7 * 86400) return $this->observation(null, 'Security patch verification must be dated within the last seven days.');
        return $this->observation($evidence['missing_patch_ids'] === [], 'Operator-supplied security patch verification.', ['verified_at' => $evidence['verified_at'], 'missing_patch_ids' => $evidence['missing_patch_ids']]);
    }

    private function twoFactor(): array
    {
        $enabled = $this->deployment->get('modules/Magento_TwoFactorAuth');
        if ($enabled === null) return $this->observation(null, '2FA module configuration is unavailable.');
        if (!(bool)$enabled) return $this->observation(false, 'Magento_TwoFactorAuth is disabled.');
        $providers = $this->scope->getValue('twofactorauth/general/force_providers');
        return $this->observation(!empty($providers), '2FA must be enabled with forced providers; custom authentication bypasses require review.');
    }

    private function secureCookies(): array
    {
        $unsafe = [];
        foreach ($this->stores->getStores() as $store) {
            if (!$this->scope->isSetFlag('web/secure/use_in_frontend', 'store', $store->getId())) $unsafe[] = (int)$store->getId();
        }
        $admin = $this->scope->isSetFlag('web/secure/use_in_adminhtml');
        return $this->observation($unsafe === [] && $admin, 'Effective frontend/admin secure transport settings control Magento secure session cookies.', ['insecure_store_ids' => $unsafe, 'admin_secure' => $admin]);
    }

    private function https(): array
    {
        $urls = []; $unsafe = [];
        foreach ($this->stores->getStores() as $store) {
            $id = $store->getId();
            $url = (string)$this->scope->getValue('web/secure/base_url', 'store', $id);
            if (!str_starts_with(strtolower($url), 'https://') || !$this->scope->isSetFlag('web/secure/use_in_frontend', 'store', $id)) $unsafe[] = (int)$id;
            else $urls[] = $url;
        }
        if ($unsafe !== [] || !$this->scope->isSetFlag('web/secure/use_in_adminhtml')) return $this->observation(false, 'Storefront/admin HTTPS configuration is disabled or uses an insecure URL.', ['insecure_store_ids' => $unsafe]);
        if ($urls === []) return $this->observation(null, 'No storefront URL is available for redirect verification.');
        foreach (array_unique($urls) as $url) {
            $client = $this->curlFactory->create();
            $client->setTimeout(10);
            $client->setOption(CURLOPT_FOLLOWLOCATION, false);
            $client->get(preg_replace('/^https:/i', 'http:', $url));
            $headers = array_change_key_case($client->getHeaders(), CASE_LOWER);
            if (!in_array($client->getStatus(), [301, 308], true) || !str_starts_with(strtolower((string)($headers['location'] ?? '')), 'https://')) return $this->observation(false, 'HTTP storefront must permanently redirect to HTTPS.');
        }
        return $this->observation(true, 'Effective HTTPS configuration and storefront redirects verified; TLS termination/API overrides require edge review.');
    }

    private function fpcEngine(): array
    {
        $engine = (string)$this->scope->getValue('system/full_page_cache/caching_application');
        $backend = strtolower((string)$this->deployment->get('cache/frontend/page_cache/backend', ''));
        $enabled = (bool)$this->deployment->get('cache_types/full_page', false);
        return $this->observation($enabled && ($engine === '2' || str_contains($backend, 'redis') || str_contains($backend, 'valkey')), 'Full-page cache must be enabled and use Varnish or Redis/Valkey.', ['enabled' => $enabled, 'engine' => $engine, 'backend' => $backend]);
    }

    private function cronRecent(): array
    {
        $found = $this->resource->getConnection()->fetchOne('SELECT 1 FROM ' . $this->table('cron_schedule') . ' WHERE executed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) LIMIT 1');
        return $this->observation((bool)$found, 'At least one cron execution must be observed within five minutes.');
    }

    private function queue(): array
    {
        if ($this->deployment->get('queue/stomp')) return $this->observation(null, 'STOMP queue telemetry requires a configured management adapter.');
        if ($this->deployment->get('queue/amqp')) {
            $management = $this->config->get('priority.rabbitmq_management', []);
            if (!is_array($management) || empty($management['url'])) return $this->observation(null, 'Configure priority.rabbitmq_management for read-only RabbitMQ health and backlog telemetry.');
            $url = rtrim((string)$management['url'], '/');
            if (strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') return $this->observation(null, 'RabbitMQ management URL must use HTTPS.');
            $client = $this->curlFactory->create();
            $client->setTimeout(10);
            $client->setCredentials((string)($management['username'] ?? ''), (string)($management['password'] ?? ''));
            $vhost = (string)$this->deployment->get('queue/amqp/virtualhost', '/');
            $client->get($url . '/api/queues/' . rawurlencode($vhost));
            if ($client->getStatus() !== 200) return $this->observation(null, 'RabbitMQ management telemetry is unavailable or access was denied.');
            $queues = json_decode($client->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($queues) || $queues === [] || !array_is_list($queues)) return $this->observation(null, 'No RabbitMQ queue telemetry was returned for the configured virtual host.');
            $backlog = 0; $unhealthy = [];
            foreach ($queues as $queue) {
                if (!isset($queue['messages'], $queue['state'])) return $this->observation(null, 'RabbitMQ telemetry is incomplete.');
                $backlog += (int)$queue['messages'];
                if ($queue['state'] !== 'running') $unhealthy[] = (string)($queue['name'] ?? 'unknown');
            }
            return $this->observation($backlog === 0 && $unhealthy === [], 'RabbitMQ queues must be running with no backlog in the configured virtual host.', ['backlog' => $backlog, 'unhealthy_queues' => $unhealthy]);
        }
        $count = (int)$this->resource->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->table('queue_message_status') . ' WHERE status IN (2, 3, 5)');
        return $this->observation($count === 0, 'Magento database queue must have no pending, in-progress, or retry messages.', ['backlog' => $count]);
    }
}
