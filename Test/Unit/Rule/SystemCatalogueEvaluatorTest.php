<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Test\Unit\Rule;

use Mha\HealthCheck\Rule\SystemCatalogueEvaluator;
use Mha\HealthCheck\Rule\RuleEngine;
use Mha\HealthCheck\Finding\FindingFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class SystemCatalogueEvaluatorTest extends TestCase
{
    public function testOnlyCsvRulesAreShippedWithExactMetadata(): void
    {
        $root = dirname(__DIR__, 3);
        $files = glob($root . '/Rule/definitions/*.yaml');
        self::assertCount(1, $files);
        $rules = Yaml::parseFile($files[0])['rules'];
        $csv = fopen($root . '/top_40_high_priority_rules.csv', 'r');
        $headers = fgetcsv($csv);
        self::assertCount(40, $rules);
        foreach ($rules as $index => $rule) {
            $row = array_combine($headers, fgetcsv($csv));
            self::assertSame(sprintf('HP-%03d', $index + 1), $rule['id']);
            self::assertSame($row['Rule Name'], $rule['title']);
            self::assertSame($row['Severity'], strtoupper($rule['risk_level']));
            self::assertSame($row['Expected Result'], $rule['expected_result']);
            self::assertSame($row['Recommendation'], $rule['recommendation']);
            self::assertSame($row['Impact if Not Resolved'], $rule['site_impact']);
        }
        self::assertFalse(fgetcsv($csv));
        fclose($csv);
    }

    public function testMissingEvidenceNeverPassesOrCreatesFindings(): void
    {
        $checks = (new SystemCatalogueEvaluator())->evaluate([]);
        self::assertCount(40, $checks);
        foreach ($checks as $check) {
            self::assertSame('not_checked', $check['status']);
            self::assertNull($check['compliant']);
        }
        $rules = Yaml::parseFile(dirname(__DIR__, 3) . '/Rule/definitions/high_priority.yaml')['rules'];
        self::assertSame([], (new RuleEngine(new FindingFactory()))->evaluate(['catalogue' => $checks], $rules, new \DateTimeImmutable()));
    }

    public function testHealthyEvidencePassesAllFortyRules(): void
    {
        foreach ((new SystemCatalogueEvaluator())->evaluate($this->healthy()) as $id => $check) {
            self::assertSame('pass', $check['status'], $id . ': ' . $check['reason']);
        }
    }

    public function testEachRuleCanFailAndProducesExactlyItsOwnFinding(): void
    {
        $changes = [
            1 => ['php', 'version', '8.1.30'], 2 => ['database', 'version', '5.7.44'],
            3 => ['opensearch', 'cluster_status', 'yellow'], 4 => ['composer', 'vulnerability_count', 1],
            5 => ['security', 'permissions', ['app/etc/env.php' => ['octal' => '0644']]],
            9 => ['php', 'memory_limit', '4095M'],
            12 => ['security_headers', 'cookie_flags', [['httponly' => true, 'samesite' => 'none']]],
            13 => ['php', 'display_errors', true], 14 => ['security', 'error_disclosure', ['status' => 'success', 'indicators' => ['stack_trace']]],
            15 => ['php', 'opcache_memory_mb', 511], 16 => ['cron', 'stale_running_count', 1],
            20 => ['database_advanced', 'long_running_queries', ['count' => 1]],
            21 => ['database_advanced', 'deadlocks', 1],
            22 => ['database_advanced', 'tables_without_primary_key', ['status' => 'success', 'tables' => ['custom_table']]],
            24 => ['database_advanced', 'slow_query_evidence', ['status' => 'success', 'max_average_seconds' => 1.1]],
            28 => ['cron', 'status_counts', ['missed' => 10, 'error' => 0]], 29 => ['fpc', 'hit_rate_percent', 84.99],
            33 => ['database_advanced', 'connection_utilization', ['status' => 'success', 'utilization_percent' => 80]],
            34 => ['redis', 'memory_utilization_percent', 100], 35 => ['system', 'inode', ['used_percent' => 85]],
            36 => ['magento', 'cache_types', ['full_page' => false]],
            38 => ['priority', 'cron_recent', ['compliant' => false]],
            39 => ['indexer', 'indexers', ['price' => ['status' => 'valid', 'mode' => 'realtime']]],
            40 => ['logs', 'exceptions', [['count' => 2]]],
        ];
        foreach ([6 => 'https', 7 => 'custom_modules', 8 => 'security_patches', 10 => 'two_factor', 11 => 'secure_cookies', 17 => 'auto_increment', 18 => 'public_backups', 19 => 'admin_path', 23 => 'changelog', 25 => 'foreign_keys', 26 => 'eav', 27 => 'duplicate_sku', 30 => 'log_size', 31 => 'table_bloat', 32 => 'queue', 37 => 'fpc_engine'] as $id => $key) {
            $changes[$id] = ['priority', $key, ['compliant' => false, 'reason' => 'Observed failure']];
        }
        self::assertCount(40, $changes);
        $rules = Yaml::parseFile(dirname(__DIR__, 3) . '/Rule/definitions/high_priority.yaml')['rules'];
        foreach ($changes as $id => [$group, $key, $value]) {
            $metrics = $this->healthy();
            $metrics[$group][$key] = $value;
            $checks = (new SystemCatalogueEvaluator())->evaluate($metrics);
            $findings = (new RuleEngine(new FindingFactory()))->evaluate(['catalogue' => $checks], $rules, new \DateTimeImmutable());
            self::assertCount(1, $findings, 'Rule ' . $id);
            self::assertSame(sprintf('HP-%03d', $id), $findings[0]->getRuleId());
        }
    }

    public function testUnavailableServicesFailButSkippedServicesRemainNotChecked(): void
    {
        $checks = (new SystemCatalogueEvaluator())->evaluate(['collector_status' => ['opensearch' => ['status' => 'unavailable'], 'redis' => ['status' => 'unavailable']]]);
        self::assertSame('fail', $checks['HP-003']['status']);
        self::assertSame('fail', $checks['HP-034']['status']);
        self::assertSame('not_checked', $checks['HP-021']['status']);
    }

    public function testUnknownTelemetryDoesNotProduceAHealthyCompositeCheck(): void
    {
        $metrics = $this->healthy();
        unset($metrics['php']['opcache'], $metrics['system']['inode']);
        $checks = (new SystemCatalogueEvaluator())->evaluate($metrics);
        self::assertSame('not_checked', $checks['HP-015']['status']);
        self::assertSame('not_checked', $checks['HP-035']['status']);
        $metrics['php']['opcache_ini_enabled'] = false;
        self::assertSame('fail', (new SystemCatalogueEvaluator())->evaluate($metrics)['HP-015']['status']);
    }

    private function healthy(): array
    {
        $priority = [];
        foreach (['https', 'custom_modules', 'security_patches', 'two_factor', 'secure_cookies', 'auto_increment', 'public_backups', 'admin_path', 'changelog', 'foreign_keys', 'eav', 'duplicate_sku', 'log_size', 'table_bloat', 'queue', 'fpc_engine', 'cron_recent'] as $key) $priority[$key] = ['compliant' => true, 'reason' => 'Verified fixture'];
        return [
            'priority' => $priority,
            'php' => ['version' => '8.3.20', 'sapi' => 'cli', 'memory_limit' => '4G', 'display_errors' => false, 'opcache_ini_enabled' => true, 'opcache_memory_mb' => 512, 'opcache' => ['hit_rate_percent' => 95.01]],
            'database' => ['version' => '10.6.22-MariaDB'],
            'opensearch' => ['cluster_status' => 'green', 'unassigned_shards' => 0],
            'composer' => ['vulnerability_count' => 0],
            'security' => ['permissions' => ['app/etc/env.php' => ['octal' => '0640']], 'error_disclosure' => ['status' => 'success', 'indicators' => []]],
            'security_headers' => ['cookie_flags' => [['httponly' => true, 'samesite' => 'lax']]],
            'magento' => ['deployment_mode' => 'production', 'cache_types' => ['full_page' => true]],
            'cron' => ['stale_running_count' => 0, 'status_counts' => ['missed' => 9, 'error' => 0]],
            'database_advanced' => ['long_running_queries' => ['count' => 0], 'deadlocks' => 0, 'tables_without_primary_key' => ['status' => 'success', 'tables' => []], 'slow_query_evidence' => ['status' => 'success', 'max_average_seconds' => 1.0], 'connection_utilization' => ['status' => 'success', 'utilization_percent' => 79.99]],
            'fpc' => ['hit_rate_percent' => 85], 'redis' => ['ping' => true, 'memory_utilization_percent' => 99.99],
            'system' => ['disk' => ['used_percent' => 84.99], 'inode' => ['used_percent' => 84.99]],
            'indexer' => ['indexers' => ['price' => ['status' => 'valid', 'mode' => 'schedule']]],
            'logs' => ['files' => ['exception.log' => 'read'], 'exceptions' => []],
        ];
    }
}
