<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Rule;

/** Evaluates only the 39 active checks (HP-007 is retired). */
class SystemCatalogueEvaluator
{
    /** @param array<string, mixed> $metrics @return array<string, array<string, mixed>> */
    public function evaluate(array $metrics): array
    {
        $checks = [];
        for ($i = 1; $i <= 40; $i++) {
            if ($i === 7) continue;
            $checks[sprintf('HP-%03d', $i)] = $this->result(null, 'Required evidence was not collected.');
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
        if (is_numeric($shards) && $shards > 0) $set(3, false, 'Search cluster has unassigned shards.', ['unassigned_shards' => $shards]);
        if ($read('collector_status.opensearch.status') === 'unavailable') $set(3, false, 'Configured search service could not be reached.');
        $number(4, 'composer.vulnerability_count', static fn(float $v): bool => $v === 0.0);
        $mode = $metrics['security']['permissions']['app/etc/env.php']['octal'] ?? null;
        $set(5, $mode === null ? null : in_array(octdec((string)$mode), [0600, 0640], true), 'env.php must have mode 0600 or 0640.', ['mode' => $mode]);
        foreach ([6 => 'https', 8 => 'security_patches', 10 => 'two_factor', 11 => 'secure_cookies', 17 => 'auto_increment', 18 => 'public_backups', 19 => 'admin_path', 23 => 'changelog', 25 => 'foreign_keys', 26 => 'eav', 30 => 'log_size', 31 => 'table_bloat', 32 => 'queue'] as $id => $key) {
            $evidence = $read('priority.' . $key);
            if (is_array($evidence)) $set($id, isset($evidence['compliant']) && is_bool($evidence['compliant']) ? $evidence['compliant'] : null, (string)($evidence['reason'] ?? 'Read-only observation.'), $evidence['details'] ?? []);
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
        if ($read('security.error_disclosure.status') === 'success') $test(14, 'security.error_disclosure.indicators', static fn($v): bool => $v === []);
        $enabled = $read('php.opcache_ini_enabled');
        $memory = $read('php.opcache_memory_mb');
        $hit = $read('php.opcache.hit_rate_percent');
        $set(15, $enabled === false || is_numeric($memory) && $memory < 512 || is_numeric($hit) && $hit <= 95 ? false : ($enabled === null || !is_numeric($memory) || !is_numeric($hit) ? null : true), 'OPcache must be enabled with at least 512 MB and a measured hit rate above 95%.', ['enabled' => $enabled, 'memory_mb' => $memory, 'hit_rate_percent' => $hit]);
        $number(16, 'cron.stale_running_count', static fn(float $v): bool => $v === 0.0);
        if ($read('database_advanced.long_running_queries.status') !== 'unavailable') $number(20, 'database_advanced.long_running_queries.count', static fn(float $v): bool => $v === 0.0);
        $number(21, 'database_advanced.deadlocks', static fn(float $v): bool => $v === 0.0);
        if ($read('database_advanced.tables_without_primary_key.status') === 'success') $test(22, 'database_advanced.tables_without_primary_key.tables', static fn($v): bool => $v === []);
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
        $set(36, !is_array($cache) || $cache === [] ? null : !in_array(false, array_map('boolval', $cache), true), 'All discovered cache types must be enabled.', ['cache_types' => $cache]);
        $fpc = $read('priority.fpc_engine');
        if (is_array($fpc)) $set(37, $fpc['compliant'] ?? null, $fpc['reason'], $fpc['details'] ?? []);
        $recent = $read('priority.cron_recent.compliant');
        $errors = $read('cron.status_counts.error');
        $set(38, $recent === false || is_numeric($errors) && $errors > 0 ? false : ($recent === null || $errors === null ? null : true), 'Cron must have executed recently and have no errors in the scan window.');
        $indexers = $read('indexer.indexers');
        if (is_array($indexers) && $indexers !== []) {
            $states = array_map(static fn(array $i): ?bool => ($i['status'] ?? 'unavailable') === 'unavailable' ? null : ($i['status'] === 'valid' && ($i['mode'] ?? '') === 'schedule'), $indexers);
            $set(39, in_array(false, $states, true) ? false : (in_array(null, $states, true) ? null : true), 'Every indexer must be Ready and Update by Schedule.', ['indexers' => $indexers]);
        }
        $logs = $read('logs.exceptions');
        if (is_array($logs) && in_array('read', $read('logs.files') ?? [], true)) {
            $repeated = array_filter($logs, static fn(array $e): bool => (int)($e['count'] ?? 0) > 1);
            $set(40, $repeated === [], 'No repeated exception signature in the bounded log sample.', ['repeated_signature_count' => count($repeated)]);
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
