<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Collector;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\State;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Module\ModuleListInterface;

class MagentoCollector implements CollectorInterface
{
    private ProductMetadataInterface $productMetadata;
    private State $appState;
    private ModuleListInterface $moduleList;
    private ComponentRegistrarInterface $componentRegistrar;
    private TypeListInterface $cacheTypeList;
    private DirectoryList $directoryList;

    public function __construct(
        ProductMetadataInterface $productMetadata,
        State $appState,
        ModuleListInterface $moduleList,
        ComponentRegistrarInterface $componentRegistrar,
        TypeListInterface $cacheTypeList,
        DirectoryList $directoryList
    ) {
        $this->productMetadata = $productMetadata;
        $this->appState = $appState;
        $this->moduleList = $moduleList;
        $this->componentRegistrar = $componentRegistrar;
        $this->cacheTypeList = $cacheTypeList;
        $this->directoryList = $directoryList;
    }

    public function getCode(): string
    {
        return 'magento';
    }

    public function isSupported(array $context = []): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(array $context = []): array
    {
        $moduleNames = $this->moduleList->getNames();
        $modulePaths = $this->componentRegistrar->getPaths(ComponentRegistrar::MODULE);
        $appCodePath = rtrim($this->directoryList->getPath(DirectoryList::ROOT), '/') . '/app/code/';
        $customModuleCount = 0;
        $enabledModules = array_fill_keys($moduleNames, true);
        $moduleInventory = [];
        foreach ($modulePaths as $moduleName => $modulePath) {
            if (str_starts_with($modulePath, $appCodePath) && !str_starts_with($moduleName, 'Magento_')) {
                $customModuleCount++;
            }
            $moduleInventory[$moduleName] = [
                'status' => isset($enabledModules[$moduleName]) ? 'enabled' : 'disabled',
                'source' => str_starts_with($moduleName, 'Magento_') ? 'core' : (str_starts_with($modulePath, $appCodePath) ? 'app_code_custom' : 'composer_or_vendor'),
            ];
        }

        $cacheTypes = [];
        foreach ($this->cacheTypeList->getTypes() as $cacheType) {
            $cacheTypes[(string)$cacheType->getId()] = (bool)$cacheType->getData('status');
        }

        return [
            'metrics' => [
                'version' => $this->productMetadata->getVersion(),
                'edition' => $this->productMetadata->getEdition(),
                'deployment_mode' => $this->appState->getMode(),
                'php_version' => PHP_VERSION,
                'php_sapi' => PHP_SAPI,
                'operating_system' => php_uname('s') . ' ' . php_uname('r'),
                'web_server' => $_SERVER['SERVER_SOFTWARE'] ?? null,
                'enabled_module_count' => count($moduleNames),
                'custom_module_count' => $customModuleCount,
                'module_inventory' => [
                    'count' => count($moduleInventory),
                    'enabled_count' => count($moduleNames),
                    'disabled_count' => max(0, count($moduleInventory) - count($moduleNames)),
                    'modules' => $moduleInventory,
                ],
                'cache_types' => $cacheTypes,
            ],
        ];
    }
}
