<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Test\Unit\Config;

use Mha\HealthCheck\Config\HealthCheckConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class HealthCheckConfigTest extends TestCase
{
    public function testReadsTypedConfigurationValuesFromYaml(): void
    {
        $moduleDirReader = $this->createMock(ModuleDirReader::class);
        $fileDriver = $this->createMock(File::class);
        $configPath = '/tmp/HealthCheck/etc/healthcheck.yaml';
        $moduleDirReader->method('getModuleDir')->with('etc', 'Mha_HealthCheck')->willReturn('/tmp/HealthCheck/etc');
        $fileDriver->method('isExists')->with($configPath)->willReturn(true);
        $fileDriver->method('fileGetContents')->with($configPath)->willReturn(<<<'YAML'
thresholds:
  large_table_mb: 2048
indexers:
  expected_schedule:
    - catalogsearch_fulltext
YAML
        );

        $config = new HealthCheckConfig(
            $moduleDirReader,
            $fileDriver,
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(ScopeConfigInterface::class)
        );

        self::assertSame(2048, $config->getPositiveInt('thresholds.large_table_mb', 1024));
        self::assertSame(['catalogsearch_fulltext'], $config->getStringList('indexers.expected_schedule'));
    }
}
