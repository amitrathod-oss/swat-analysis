<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Test\Unit\Collector;

use Mha\HealthCheck\Collector\PriorityCollector;
use Mha\HealthCheck\Config\HealthCheckConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class PriorityCollectorTest extends TestCase
{
    private function collector(AdapterInterface $db, array $configuration = []): PriorityCollector
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnCallback(static fn($name) => 'shop_' . $name);
        $directory = $this->createMock(DirectoryList::class);
        $directory->method('getRoot')->willReturn('/nonexistent-swat-test');
        $config = $this->createMock(HealthCheckConfig::class);
        $config->method('get')->willReturnCallback(static fn($key, $default = null) => $configuration[$key] ?? $default);
        $product = $this->createMock(ProductMetadataInterface::class);
        $product->method('getVersion')->willReturn('2.4.7-p3');
        return new PriorityCollector($resource, $directory, $this->createMock(DeploymentConfig::class), $this->createMock(ScopeConfigInterface::class), $this->createMock(StoreManagerInterface::class), $this->createMock(CurlFactory::class), $config, $product);
    }

    public function testAutoIncrementUsesColumnSignednessAndEightyPercentBoundary(): void
    {
        $db = $this->createMock(AdapterInterface::class);
        $db->method('fetchAll')->willReturnCallback(static function ($query): array {
            if (str_contains($query, 'AUTO_INCREMENT')) return [
                ['TABLE_NAME' => 'unsigned_safe', 'AUTO_INCREMENT' => '200', 'DATA_TYPE' => 'tinyint', 'COLUMN_TYPE' => 'tinyint unsigned'],
                ['TABLE_NAME' => 'signed_risk', 'AUTO_INCREMENT' => '102', 'DATA_TYPE' => 'tinyint', 'COLUMN_TYPE' => 'tinyint'],
            ];
            return [];
        });
        $result = $this->collector($db)->collect()['metrics']['auto_increment'];
        self::assertFalse($result['compliant']);
        self::assertSame(['signed_risk'], array_keys($result['details']['at_risk_tables_percent']));
    }

    public function testDatabaseAccessFailuresStayUnknownAndOtherProbesContinue(): void
    {
        $db = $this->createMock(AdapterInterface::class);
        $db->method('fetchAll')->willThrowException(new \RuntimeException('Access denied'));
        $result = $this->collector($db)->collect()['metrics'];
        self::assertNull($result['auto_increment']['compliant']);
        self::assertNull($result['foreign_keys']['compliant']);
        self::assertArrayHasKey('cron_recent', $result);
    }

    public function testDuplicateSkuQueryUsesMagentoTablePrefix(): void
    {
        $db = $this->createMock(AdapterInterface::class);
        $db->method('quoteIdentifier')->willReturnCallback(static fn($name) => '`' . $name . '`');
        $db->method('fetchOne')->willReturnCallback(static function ($query) {
            if (str_contains($query, 'GROUP BY sku')) {
                self::assertStringContainsString('`shop_catalog_product_entity`', $query);
                return 1;
            }
            return 0;
        });
        self::assertFalse($this->collector($db)->collect()['metrics']['duplicate_sku']['compliant']);
    }

    public function testPatchEvidenceMustBeRecentAndForThisExactRelease(): void
    {
        $db = $this->createMock(AdapterInterface::class);
        $evidence = ['verified_at' => gmdate('c'), 'magento_version' => '2.4.7-p3', 'missing_patch_ids' => []];
        self::assertTrue($this->collector($db, ['priority.security_patch_evidence' => $evidence])->collect()['metrics']['security_patches']['compliant']);
        $evidence['magento_version'] = '2.4.6';
        self::assertNull($this->collector($db, ['priority.security_patch_evidence' => $evidence])->collect()['metrics']['security_patches']['compliant']);
        $evidence['magento_version'] = '2.4.7-p3';
        $evidence['verified_at'] = '2000-01-01';
        self::assertNull($this->collector($db, ['priority.security_patch_evidence' => $evidence])->collect()['metrics']['security_patches']['compliant']);
    }
}
