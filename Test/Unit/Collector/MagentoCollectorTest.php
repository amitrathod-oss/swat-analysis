<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Test\Unit\Collector;

use Sigma\HealthCheck\Collector\MagentoCollector;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\State;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\DataObject;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Module\ModuleListInterface;
use PHPUnit\Framework\TestCase;

class MagentoCollectorTest extends TestCase
{
    public function testCollectReturnsEnvironmentAndCacheInventory(): void
    {
        $metadata = $this->createMock(ProductMetadataInterface::class);
        $appState = $this->createMock(State::class);
        $moduleList = $this->createMock(ModuleListInterface::class);
        $registrar = $this->createMock(ComponentRegistrarInterface::class);
        $cacheTypeList = $this->createMock(TypeListInterface::class);
        $directoryList = $this->createMock(DirectoryList::class);
        $metadata->method('getVersion')->willReturn('2.4.7-p8');
        $metadata->method('getEdition')->willReturn('Community');
        $appState->method('getMode')->willReturn('developer');
        $moduleList->method('getNames')->willReturn(['Magento_Catalog', 'Sigma_HealthCheck']);
        $registrar->method('getPaths')->with(ComponentRegistrar::MODULE)->willReturn([
            'Magento_Catalog' => '/var/www/html/app/code/Magento/Catalog',
            'Sigma_HealthCheck' => '/var/www/html/app/code/Sigma/HealthCheck',
        ]);
        $directoryList->method('getPath')->with(DirectoryList::ROOT)->willReturn('/var/www/html');
        $cacheTypeList->method('getTypes')->willReturn([
            new DataObject(['id' => 'config', 'status' => 1]),
            new DataObject(['id' => 'full_page', 'status' => 0]),
        ]);

        $result = (new MagentoCollector(
            $metadata,
            $appState,
            $moduleList,
            $registrar,
            $cacheTypeList,
            $directoryList
        ))->collect();

        self::assertSame('2.4.7-p8', $result['metrics']['version']);
        self::assertSame(2, $result['metrics']['enabled_module_count']);
        self::assertSame(1, $result['metrics']['custom_module_count']);
        self::assertTrue($result['metrics']['cache_types']['config']);
        self::assertFalse($result['metrics']['cache_types']['full_page']);
    }
}
