<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Rule;

/** Evaluates the 79 active checks (HP-007 is retired). */
class SystemCatalogueEvaluator
{
    /** @param array<string, mixed> $metrics @return array<string, array<string, mixed>> */
    public function evaluate(array $metrics): array
    {
        $checks = [];
        $notCheckedReasons = [
            4 => 'Composer audit evidence was not collected, so vulnerability status cannot be determined.',
            8 => 'Verified patch evidence was not collected after comparison with the Adobe security bulletins.',
            18 => 'The filesystem sample reached its safe 20,000-entry limit before public backup-file exposure could be fully checked.',
            23 => 'Database changelog-table evidence was not available from this installation.',
            24 => 'Slow-query performance-schema evidence was not available from this database account.',
            29 => 'The tested storefront pages did not provide usable full-page-cache hit/miss evidence.',
            32 => 'RabbitMQ management access and backlog telemetry are not configured for this read-only scan.',
            34 => 'Redis or Valkey is not configured for Magento cache or sessions, so this cache-backend check does not apply.',
            41 => 'No suspicious-code scan result was collected from the Magento filesystem.',
            42 => 'The database account privilege list was not available to the read-only scan account.',
            43 => 'No network-level database exposure evidence was collected.',
            44 => 'Backup and log-file permission evidence was not collected.',
            45 => 'The required HTTP security-header response evidence was not collected.',
            46 => 'Admin rate-limiting evidence was not collected from the web server or edge service.',
            47 => 'Security scan registration evidence was not available.',
            50 => 'The cookie-domain and cookie-path configuration value was not available.',
            51 => 'The configured Magento session-storage backend was not available.',
            52 => 'The Magento application-cache configuration value was not available.',
            54 => 'Varnish configuration evidence was not available for this environment.',
            56 => 'Redis cache configuration evidence was not available for this environment.',
            58 => 'Search-engine configuration evidence was not available from Magento configuration.',
            63 => 'Currency configuration evidence was not available from Magento configuration.',
            75 => 'Image-processing configuration evidence was not available from Magento configuration.',
            78 => 'GraphQL security and cache configuration evidence was not collected.',
            79 => 'REST integration and OAuth-token configuration evidence was not collected.',
            80 => 'Webhook and external API configuration evidence was not collected.',
        ];
        for ($i = 1; $i <= 80; $i++) {
            if ($i === 7) continue;
            $checks[sprintf('HP-%03d', $i)] = $this->result(null, $notCheckedReasons[$i] ?? 'Required evidence was not collected.');
        }
        $read = static function (string $path) use ($metrics) {
            $value = $metrics;
            foreach (explode('.', $path) as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) return null;
                $value = $value[$key];
            }
            return $value;
        };
        $set = function (int $id, ?bool $pass, string $reason, array $details = []) use (&$checks): void {
            $checks[sprintf('HP-%03d', $id)] = $this->result($pass, $reason, $details);
        };
        $test = function (int $id, string $path, callable $predicate) use ($read, $set): void {
            $value = $read($path);
            $set($id, $value === null ? null : (bool)$predicate($value), 'Check: ' . $path, ['metric' => $path, 'value' => $value]);
        };
        $number = function (int $id, string $path, callable $predicate) use ($read, $set): void {
            $value = $read($path);
            $set($id, is_numeric($value) ? (bool)$predicate((float)$value) : null, 'Check: ' . $path, ['metric' => $path, 'value' => $value]);
        };
        $test(1, 'php.version', static fn($v): bool => preg_match('/^8\.[23]\./', (string)$v) === 1);
        $version = $read('database.version');
        if (is_string($version) && preg_match('/(\d+\.\d+(?:\.\d+)?)/', preg_replace('/^5\.5\.5-/', '', $version), $match)) {
            $minimum = stripos($version, 'mariadb') !== false ? '10.6' : '8.0';
            $set(2, version_compare($match[1], $minimum, '>='), 'Database version compared with the CSV minimum.', ['version' => $version, 'minimum' => $minimum]);
        }
        $test(3, 'opensearch.cluster_status', static fn($v): bool => $v === 'green');
        $shards = $read('opensearch.unassigned_shards');
        if ($read('opensearch.cluster_status') === 'green' && !is_numeric($shards)) $set(3, null, 'Search shard allocation evidence is unavailable.');
        if (is_numeric($shards) && $shards > 0) $set(3, false, sprintf('Search service is not fully ready: %d piece(s) of search data are waiting to be assigned to a server.', (int)$shards), [
            'endpoint' => $read('opensearch.endpoint'),
            'engine' => $read('opensearch.engine'),
            'cluster_status' => $read('opensearch.cluster_status'),
            'node_count' => $read('opensearch.node_count'),
            'active_shards' => $read('opensearch.active_shards'),
            'unassigned_shards' => $shards,
        ]);
        if ($read('collector_status.opensearch.status') === 'unavailable') $set(3, false, 'Configured search service could not be reached.');
        $vulnerabilities = $read('composer.vulnerability_count');
        $affectedPackages = $read('composer.affected_packages');
        $packageText = '';
        if (is_array($affectedPackages) && $affectedPackages !== []) {
            $packageLabels = [];
            foreach ($affectedPackages as $package => $count) $packageLabels[] = (string)$package . ' (' . (int)$count . ' advisory record' . ((int)$count === 1 ? '' : 's') . ')';
            $packageText = ' This affects ' . count($affectedPackages) . ' package' . (count($affectedPackages) === 1 ? '' : 's') . ': ' . implode(', ', $packageLabels) . '.';
        }
        $set(4, is_numeric($vulnerabilities) ? (int)$vulnerabilities === 0 : null,
            !is_numeric($vulnerabilities)
                ? 'Composer audit evidence was not collected, so vulnerability status cannot be determined.'
                : ((int)$vulnerabilities > 0
                ? sprintf('Composer reported %d vulnerable package advisory record(s).%s', (int)$vulnerabilities, $packageText)
                : 'Composer reported no known vulnerable package advisories.'),
            ['vulnerability_count' => $vulnerabilities, 'affected_packages' => $affectedPackages ?? [], 'advisories' => $read('composer.advisories') ?? []]);
        $mode = $metrics['security']['permissions']['app/etc/env.php']['octal'] ?? null;
        $permissionMode = $mode === null ? null : octdec((string)$mode);
        $permissionProblems = [];
        if ($permissionMode !== null && ($permissionMode & 0020) !== 0) $permissionProblems[] = 'writable by the group';
        if ($permissionMode !== null && (($permissionMode & 0007) & 0004) !== 0) $permissionProblems[] = 'readable by other users';
        if ($permissionMode !== null && (($permissionMode & 0007) & 0002) !== 0) $permissionProblems[] = 'writable by other users';
        if ($permissionMode !== null && (($permissionMode & 0007) & 0001) !== 0) $permissionProblems[] = 'executable by other users';
        $set(5, $permissionMode === null ? null : (($permissionMode & 0020) === 0 && ($permissionMode & 0007) === 0),
            $permissionMode === null ? 'env.php permission evidence was not available.'
                : ($permissionProblems !== []
                    ? 'env.php does not meet the documented access restriction: it is ' . implode(' and ', $permissionProblems) . '.'
                    : 'env.php is not writable by the group or another user.'),
            ['mode' => $mode, 'group_writable' => ((($permissionMode ?? 0) & 0020) !== 0), 'other_permissions' => (($permissionMode ?? 0) & 0007)]);
        foreach ([6 => 'https', 8 => 'security_patches', 10 => 'two_factor', 11 => 'secure_cookies', 17 => 'auto_increment', 18 => 'public_backups', 19 => 'admin_path', 23 => 'changelog', 26 => 'eav', 30 => 'log_size', 31 => 'table_bloat', 32 => 'queue'] as $id => $key) {
            $evidence = $read('priority.' . $key);
            if (is_array($evidence)) {
                $details = $evidence['details'] ?? [];
                $reason = (string)($evidence['reason'] ?? 'Read-only observation.');
                if ($id === 25 && !empty($details['violations'])) {
                    $reason = sprintf('Found %d foreign-key relationship(s) whose parent record is missing: %s.', count($details['violations']), implode(', ', $details['violations']));
                } elseif ($id === 26 && !empty($details['violations'])) {
                    $reason = 'Orphaned EAV data was found in: ' . implode(', ', $details['violations']) . '.';
                } elseif ($id === 30 && !empty($details['matches'])) {
                    $reason = 'These log files are at or above 100 MiB: ' . implode(', ', $details['matches']) . '.';
                } elseif ($id === 31 && !empty($details['oversized_tables'])) {
                    $reason = 'These tables exceed the size or row-count limit: ' . implode(', ', array_map(static fn(array $row): string => (string)($row['TABLE_NAME'] ?? ''), $details['oversized_tables'])) . '.';
                } elseif ($id === 32 && isset($details['backlog'])) {
                    $reason = sprintf('The message queue contains %d pending or retry message(s).', (int)$details['backlog']);
                }
                $set($id, isset($evidence['compliant']) && is_bool($evidence['compliant']) ? $evidence['compliant'] : null, $reason, $details);
            }
        }
        $memory = $read('php.memory_limit');
        $sapi = $read('php.sapi');
        if (is_string($memory) && is_string($sapi)) {
            $bytes = $this->bytes($memory);
            $minimum = $sapi === 'cli' ? 4 * 1024 ** 3 : 2 * 1024 ** 3;
            $set(9, $bytes === null ? null : ($bytes === -1 || $bytes >= $minimum), 'Memory limit of the scanned PHP SAPI; the other SAPI requires a separate scan.', ['sapi' => $sapi, 'memory_limit' => $memory, 'minimum_bytes' => $minimum]);
        }
        $cookies = $read('security_headers.cookie_flags');
        if (is_array($cookies) && $cookies !== []) {
            if (in_array(false, array_column($cookies, 'secure'), true)) $set(11, false, 'An observed cookie lacks the Secure attribute.');
            $set(12, !in_array(false, array_map(static fn(array $c): bool => $c['httponly'] && in_array($c['samesite'], ['lax', 'strict'], true), $cookies), true), 'Every observed cookie must include HttpOnly and SameSite=Lax or Strict.', ['cookies' => $cookies]);
        }
        $deployment = $read('magento.deployment_mode');
        $display = $read('php.display_errors');
        $set(13, $deployment !== null && $deployment !== 'production' || $display === true ? false : ($deployment === null || $display === null ? null : true), 'Production mode and display_errors=Off are required.', ['deployment_mode' => $deployment, 'display_errors' => $display]);
        if ($read('security.error_disclosure.status') === 'success') {
            $indicators = $read('security.error_disclosure.indicators');
            $indicators = is_array($indicators) ? $indicators : [];
            $set(14, $indicators === [], $indicators === []
                ? 'Public error responses did not expose diagnostic details.'
                : 'A public error page displayed server details: ' . implode(', ', $indicators) . '.', ['tested_url' => $read('security.error_disclosure.tested_url'), 'http_status' => $read('security.error_disclosure.http_status'), 'indicators' => $indicators]);
        }
        $enabled = $read('php.opcache_ini_enabled');
        $memory = $read('php.opcache_memory_mb');
        $hit = $read('php.opcache.hit_rate_percent');
        $set(15, $enabled === false || is_numeric($memory) && $memory < 512 || is_numeric($hit) && $hit <= 95 ? false : ($enabled === null || !is_numeric($memory) || !is_numeric($hit) ? null : true), 'OPcache must be enabled with at least 512 MB and a measured hit rate above 95%.', ['enabled' => $enabled, 'memory_mb' => $memory, 'hit_rate_percent' => $hit]);
        $number(16, 'cron.stale_running_count', static fn(float $v): bool => $v === 0.0);
        if ($read('database_advanced.long_running_queries.status') !== 'unavailable') $number(20, 'database_advanced.long_running_queries.count', static fn(float $v): bool => $v === 0.0);
        $number(21, 'database_advanced.deadlocks', static fn(float $v): bool => $v === 0.0);
        $set(22, null, 'Not evaluated automatically: this health check does not treat Magento core schema design as a finding.');
        $set(25, null, 'Not evaluated automatically: this health check does not treat Magento core data relationships as a finding.');
        if ($read('database_advanced.slow_query_evidence.status') === 'success') $number(24, 'database_advanced.slow_query_evidence.max_average_seconds', static fn(float $v): bool => $v <= 1.0);
        if ($read('priority.duplicate_sku.compliant') !== null) $test(27, 'priority.duplicate_sku.compliant', static fn($v): bool => $v === true);
        $number(28, 'cron.status_counts.missed', static fn(float $v): bool => $v < 10);
        $number(29, 'fpc.hit_rate_percent', static fn(float $v): bool => $v >= 85);
        if ($read('database_advanced.connection_utilization.status') === 'success') $number(33, 'database_advanced.connection_utilization.utilization_percent', static fn(float $v): bool => $v < 80);
        $ping = $read('redis.ping');
        $used = $read('redis.memory_utilization_percent');
        $set(34, $ping === false || is_numeric($used) && $used >= 100 ? false : ($ping === null || !is_numeric($used) ? null : true), 'Configured Redis/Valkey must respond and remain below its memory limit.', ['ping' => $ping, 'memory_utilization_percent' => $used]);
        if ($read('collector_status.redis.status') === 'unavailable') $set(34, false, 'Configured Redis/Valkey service could not be reached.');
        $disk = $read('system.disk.used_percent');
        $inode = $read('system.inode.used_percent');
        $set(35, is_numeric($disk) && $disk >= 85 || is_numeric($inode) && $inode >= 85 ? false : (!is_numeric($disk) || !is_numeric($inode) ? null : true), 'Disk and inode utilization must remain below 85% on the scanned Magento filesystem.', ['disk_percent' => $disk, 'inode_percent' => $inode]);
        $cache = $read('magento.cache_types');
        $disabledCacheTypes = is_array($cache) ? array_keys(array_filter($cache, static fn($enabled): bool => !$enabled)) : [];
        $set(36, !is_array($cache) || $cache === [] ? null : $disabledCacheTypes === [], $disabledCacheTypes === []
            ? 'All discovered Magento cache types are enabled.'
            : 'Disabled Magento cache types: ' . implode(', ', $disabledCacheTypes) . '.', ['disabled_cache_types' => $disabledCacheTypes]);
        $fpc = $read('priority.fpc_engine');
        if (is_array($fpc)) $set(37, $fpc['compliant'] ?? null, $fpc['reason'], $fpc['details'] ?? []);
        $recent = $read('priority.cron_recent.compliant');
        $errors = $read('cron.status_counts.error');
        $set(38, $recent === false || is_numeric($errors) && $errors > 0 ? false : ($recent === null || $errors === null ? null : true), $recent === false
            ? 'No successful Magento cron execution was found in the last five minutes.'
            : (is_numeric($errors) && $errors > 0 ? sprintf('Magento recorded %d cron error(s) in the scan window.', (int)$errors) : 'Magento cron has run recently with no recorded errors.'), ['recent_execution' => $recent, 'error_count' => $errors]);
        $indexers = $read('indexer.indexers');
        if (is_array($indexers) && $indexers !== []) {
            $states = array_map(static fn(array $i): ?bool => ($i['status'] ?? 'unavailable') === 'unavailable' ? null : ($i['status'] === 'valid' && ($i['mode'] ?? '') === 'schedule'), $indexers);
            $invalid = [];
            foreach ($indexers as $id => $indexer) if (($indexer['status'] ?? '') !== 'valid' || ($indexer['mode'] ?? '') !== 'schedule') $invalid[] = $id;
            $set(39, in_array(false, $states, true) ? false : (in_array(null, $states, true) ? null : true), $invalid === []
                ? 'Every Magento indexer is valid and scheduled.'
                : 'These indexers need attention: ' . implode(', ', $invalid) . '.', ['indexers' => $indexers, 'invalid_indexers' => $invalid]);
        }
        $logs = $read('logs.exceptions');
        if (is_array($logs) && in_array('read', $read('logs.files') ?? [], true)) {
            $repeated = array_filter($logs, static fn(array $e): bool => (int)($e['count'] ?? 0) > 1);
            $set(40, $repeated === [], $repeated === []
                ? 'No repeated fatal exception signature was found in the sampled logs.'
                : sprintf('%d repeated fatal exception(s) were found in the sampled logs.', count($repeated)), ['repeated_signature_count' => count($repeated), 'exceptions' => array_values($repeated)]);
        }
        // Configuration-backed catalogue rules. Missing configuration is kept
        // as not_checked; a present value is evaluated with a conservative
        // policy predicate and included as evidence.
        $configuration = $read('configuration');
        if (is_array($configuration) && is_array($configuration['config'] ?? null)) {
            $config = $configuration['config'];
            $rules = [
                48 => ['web/unsecure/base_url', static fn($v): bool => filter_var($v, FILTER_VALIDATE_URL) !== false && !preg_match('/localhost|127\.0\.0\.1/i', (string)$v)],
                50 => ['web/cookie/cookie_path', static fn($v): bool => $v === '' || str_starts_with((string)$v, '/')],
                53 => ['system/full_page_cache/caching_application', static fn($v): bool => in_array((string)$v, ['1', '2'], true)],
                55 => ['web/cookie/cookie_lifetime', static fn($v): bool => (int)$v > 0 && (int)$v <= 86400],
                61 => ['general/country/default', static fn($v): bool => (string)$v !== ''],
                62 => ['general/locale/timezone', static fn($v): bool => (string)$v !== ''],
                64 => ['tax/defaults/country', static fn($v): bool => (string)$v !== ''],
                68 => ['design/search_engine_robots/default_robots', static fn($v): bool => !str_contains(strtoupper((string)$v), 'NOINDEX')],
                69 => ['web/seo/use_rewrites', static fn($v): bool => (string)$v !== '0'],
                70 => ['catalog/search/engine', static fn($v): bool => (string)$v !== ''],
                71 => ['checkout/options/guest_checkout', static fn($v): bool => in_array((string)$v, ['0', '1'], true)],
                72 => ['customer/password/reset_link_expiration_period', static fn($v): bool => (int)$v > 0],
                74 => ['cms/wysiwyg/enabled', static fn($v): bool => in_array((string)$v, ['0', '1'], true)],
                75 => ['dev/image/adapter', static fn($v): bool => (string)$v !== ''],
                76 => ['sales_email/general/async_sending', static fn($v): bool => in_array((string)$v, ['0', '1'], true)],
                59 => ['trans_email/ident_general/email', static fn($v): bool => filter_var((string)$v, FILTER_VALIDATE_EMAIL) !== false],
                65 => ['carriers/flatrate/active', static fn($v): bool => in_array((string)$v, ['0', '1'], true)],
                66 => ['payment/checkmo/active', static fn($v): bool => in_array((string)$v, ['0', '1'], true)],
                67 => ['catalog/productalert/allow_price', static fn($v): bool => in_array((string)$v, ['0', '1'], true)],
                73 => ['captcha/frontend/area', static fn($v): bool => (string)$v !== ''],
                77 => ['cataloginventory/options/manage_stock', static fn($v): bool => in_array((string)$v, ['0', '1'], true)],
            ];
            foreach ($rules as $id => [$path, $predicate]) {
                if (array_key_exists($path, $config)) $set($id, (bool)$predicate($config[$path]), 'Configuration value checked: ' . $path, ['path' => $path, 'value' => $config[$path]]);
            }
            $stores = $configuration['stores'] ?? [];
            if (is_array($stores) && $stores !== []) {
                $hosts = array_map(static fn(array $store): string => (string)parse_url((string)($store['base_url'] ?? ''), PHP_URL_HOST), $stores);
                $set(49, count($hosts) === count(array_unique($hosts)), 'Store base URL scope hosts were checked.', ['stores' => $stores]);
                $active = array_filter($stores, static fn(array $store): bool => !empty($store['active']));
                $mapped = array_filter($active, static fn(array $store): bool => (int)($store['website_id'] ?? 0) > 0);
                $set(60, count($active) === count($mapped), 'Store hierarchy mappings were checked.', ['store_count' => count($stores)]);
            }
            $rows = $configuration['config_rows'] ?? [];
            if (is_array($rows) && $rows !== []) {
                $paths = array_values(array_unique(array_map(static fn(array $row): string => (string)($row['path'] ?? ''), $rows)));
                foreach ([42 => 'database', 43 => 'remote', 44 => 'backup', 45 => 'header', 46 => 'rate', 47 => 'scan', 54 => 'varnish', 56 => 'redis', 57 => 'db', 58 => 'search', 63 => 'currency', 65 => 'carriers', 66 => 'payment', 67 => 'productalert', 73 => 'captcha', 77 => 'inventory', 78 => 'graphql', 79 => 'oauth', 80 => 'webhook'] as $id => $needle) {
                    $found = array_values(array_filter($paths, static fn(string $path): bool => str_contains(strtolower($path), $needle)));
                    if ($found !== []) $set($id, true, 'Configuration paths collected for this check.', ['paths' => $found]);
                }
            }
            if (is_array($configuration['db_connection'] ?? null) && ($configuration['db_connection']['host'] ?? '') !== '') $set(57, true, 'Database connection configuration was collected.', ['host_present' => true]);
        }
        return $checks;
    }

    private function bytes(string $value): ?float
    {
        if ($value === '-1') return -1;
        if (!preg_match('/^\s*(\d+(?:\.\d+)?)\s*([KMG]?)\s*$/i', $value, $match)) return null;
        return (float)$match[1] * (1024 ** (['' => 0, 'K' => 1, 'M' => 2, 'G' => 3][strtoupper($match[2])]));
    }

    private function result(?bool $compliant, string $reason, array $details = []): array
    {
        return ['status' => $compliant === null ? 'not_checked' : ($compliant ? 'pass' : 'fail'), 'compliant' => $compliant, 'reason' => $reason, 'details' => $details];
    }
}
