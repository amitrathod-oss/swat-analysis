<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Test\Unit\Rule;

use Mha\HealthCheck\Rule\SystemCatalogueEvaluator;
use PHPUnit\Framework\TestCase;

class SystemCatalogueEvaluatorTest extends TestCase
{
    public function testEvaluatesSupportedEnvironmentAndKeepsUnconfiguredCacheNotChecked(): void
    {
        $checks = (new SystemCatalogueEvaluator())->evaluate($this->metrics());

        self::assertCount(98, $checks);
        self::assertSame('pass', $checks['SYS-001']['status']);
        self::assertSame('pass', $checks['SYS-006']['status']);
        self::assertSame('not_checked', $checks['SYS-007']['status']);
        self::assertTrue($checks['SYS-007']['compliant']);
        self::assertSame('pass', $checks['SYS-010']['status']);
        foreach (['SYS-011', 'SYS-013', 'SYS-015', 'SYS-017', 'SYS-018', 'SYS-020', 'SEC-004', 'SEC-008', 'SEC-010', 'SEC-011', 'SEC-014', 'SEC-015', 'SEC-019', 'SEC-020', 'SEC-022', 'SEC-024'] as $ruleId) {
            self::assertSame('pass', $checks[$ruleId]['status'], $ruleId . ' should pass for the healthy fixture.');
        }
    }

    public function testFlagsIncompatibleConfiguredDependencies(): void
    {
        $metrics = $this->metrics();
        $metrics['php']['version'] = '8.1.34';
        $metrics['composer']['version'] = 'Composer version 2.2.0';
        $metrics['database']['version'] = '10.6.22-MariaDB';
        $metrics['collector_status']['opensearch']['status'] = 'unavailable';
        $metrics['collector_status']['redis']['status'] = 'unavailable';
        $metrics['system']['operating_system'] = ['id' => 'ubuntu', 'version_id' => '20.04'];

        $checks = (new SystemCatalogueEvaluator())->evaluate($metrics);

        foreach (['SYS-003', 'SYS-004', 'SYS-005', 'SYS-006', 'SYS-007', 'SYS-009'] as $ruleId) {
            self::assertSame('fail', $checks[$ruleId]['status'], $ruleId . ' should fail.');
        }
    }

    public function testFlagsRequestedSystemAndSecurityCatalogueRisks(): void
    {
        $metrics = $this->metrics();
        $metrics['magento']['deployment_mode'] = 'developer';
        $metrics['magento']['module_inventory']['modules']['Vendor_Unprovenanced'] = ['status' => 'enabled', 'source' => 'app_code_custom'];
        $metrics['cron']['installation'] = ['status' => 'missing'];
        $metrics['indexer']['indexers']['catalog_product_price']['status'] = 'suspended';
        $metrics['system']['unsafe_scripts'] = ['status' => 'success', 'matches' => [['path' => 'bin/unsafe.php', 'mode' => '0777']]];
        $metrics['system']['disk']['used_percent'] = 90;
        $metrics['php']['opcache_ini_enabled'] = false;
        $metrics['security']['admin_roles']['unrestricted'] = [['username' => 'admin', 'role' => 'Administrators']];
        $metrics['security']['admin_security_config']['values'][0]['value'] = '0';
        $metrics['security']['cookie_secure_config']['values'][0]['value'] = '0';
        $metrics['magento']['module_inventory']['modules']['Magento_TwoFactorAuth']['status'] = 'disabled';
        $metrics['security_headers']['headers']['set-cookie'] = 'PHPSESSID=value';
        $metrics['security']['error_disclosure']['indicators'] = ['stack_trace'];
        $metrics['security']['suspicious_code']['matches'] = [['path' => 'app/code/Vendor/Module/Bad.php', 'tokens' => ['eval']]];

        $checks = (new SystemCatalogueEvaluator())->evaluate($metrics);

        foreach (['SYS-011', 'SYS-013', 'SYS-015', 'SYS-017', 'SYS-018', 'SYS-020', 'SEC-004', 'SEC-008', 'SEC-010', 'SEC-011', 'SEC-014', 'SEC-015', 'SEC-019', 'SEC-020', 'SEC-022', 'SEC-024'] as $ruleId) {
            self::assertSame('fail', $checks[$ruleId]['status'], $ruleId . ' should fail for the risk fixture.');
        }
    }

    /** @return array<string, mixed> */
    private function metrics(): array
    {
        return [
            'magento' => [
                'version' => '2.4.8-p5',
                'edition' => 'Community',
                'deployment_mode' => 'production',
                'module_inventory' => ['count' => 2, 'enabled_count' => 2, 'disabled_count' => 0, 'modules' => ['Magento_Catalog' => ['status' => 'enabled', 'source' => 'core'], 'Magento_TwoFactorAuth' => ['status' => 'enabled', 'source' => 'core']]],
            ],
            'php' => ['version' => '8.3.33', 'sapi' => 'fpm-fcgi', 'memory_limit' => '512M', 'max_execution_time' => 30, 'max_input_time' => 30, 'upload_max_filesize' => '10M', 'post_max_size' => '10M', 'opcache_ini_enabled' => true, 'display_errors' => false, 'xdebug_loaded' => false],
            'composer' => ['version' => 'Composer version 2.10.0'],
            'database' => ['version' => '11.4.5-MariaDB'],
            'opensearch' => ['version' => '3.0.0'],
            'redis' => [],
            'system' => [
                'web_server' => ['status' => 'success', 'binary' => 'nginx', 'version' => '1.30.0'],
                'operating_system' => ['id' => 'ubuntu', 'version_id' => '24.04', 'name' => 'Ubuntu 24.04'],
                'disk' => ['used_percent' => 20],
                'inode' => ['status' => 'success', 'used_percent' => 10],
                'writable_directories' => ['var' => ['exists' => true, 'writable' => true], 'pub/static' => ['exists' => true, 'writable' => true], 'generated' => ['exists' => true, 'writable' => true]],
                'unsafe_scripts' => ['status' => 'success', 'matches' => []],
            ],
            'cron' => ['installation' => ['status' => 'installed']],
            'indexer' => ['indexers' => ['catalog_product_price' => ['status' => 'valid']]],
            'extensions' => ['modules' => [['name' => 'Magento_TwoFactorAuth', 'package' => 'magento/module-two-factor-auth']]],
            'security' => [
                'admin_roles' => ['status' => 'success', 'unrestricted' => []],
                'admin_security_config' => ['status' => 'success', 'values' => [['path' => 'admin/security/lockout_failures', 'value' => '5'], ['path' => 'admin/security/lockout_threshold', 'value' => '30'], ['path' => 'admin/security/session_lifetime', 'value' => '900']],],
                'cookie_secure_config' => ['status' => 'success', 'values' => [['path' => 'web/cookie/cookie_secure', 'value' => '1']]],
                'tls' => ['status' => 'valid'],
                'error_disclosure' => ['status' => 'success', 'indicators' => []],
                'suspicious_code' => ['status' => 'success', 'matches' => []],
            ],
            'security_headers' => ['status' => 'success', 'tls' => 'https', 'headers' => ['set-cookie' => 'PHPSESSID=value; HttpOnly; SameSite=Lax']],
            'collector_status' => [
                'opensearch' => ['status' => 'success'],
                'redis' => ['status' => 'not_applicable'],
            ],
        ];
    }
}
