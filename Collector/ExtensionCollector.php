<?php
declare(strict_types=1);

namespace Asiamarket\HealthCheck\Collector;

use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Module\PackageInfo;

class ExtensionCollector implements CollectorInterface
{
    private ModuleListInterface $moduleList;
    private PackageInfo $packageInfo;

    public function __construct(ModuleListInterface $moduleList, PackageInfo $packageInfo)
    {
        $this->moduleList = $moduleList;
        $this->packageInfo = $packageInfo;
    }

    public function getCode(): string
    {
        return 'extensions';
    }

    public function isSupported(array $context = []): bool
    {
        return true;
    }

    public function collect(array $context = []): array
    {
        $names = $this->moduleList->getNames();
        $modules = [];
        $modulePackages = [];
        foreach ($names as $name) {
            $data = $this->moduleList->getOne($name);
            $version = is_array($data) ? trim((string)($data['setup_version'] ?? '')) : '';
            $packageName = null;
            try {
                $packageName = trim((string)$this->packageInfo->getPackageName($name)) ?: null;
                if ($packageName !== null) {
                    $modulePackages[$packageName] = true;
                }
            } catch (\Throwable $exception) {
                // Some modules are not Composer packages; keep their version honest.
            }
            if ($version === '') {
                try {
                    $packageVersion = trim((string)$this->packageInfo->getVersion($name));
                    $version = $packageVersion;
                } catch (\Throwable $exception) {
                    $version = '';
                }
            }
            $modules[] = [
                'name' => $name,
                'enabled' => true,
                'version' => $version !== '' ? $version : null,
                'package' => $packageName,
                'type' => str_starts_with($name, 'Magento_') ? 'platform' : 'custom_or_vendor',
            ];
        }

        $libraries = [];
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                $installedPackages = method_exists(\Composer\InstalledVersions::class, 'getInstalledPackages')
                    ? \Composer\InstalledVersions::getInstalledPackages()
                    : \Composer\InstalledVersions::getInstalledPackagesByType('library');
                foreach ($installedPackages as $packageName) {
                    $packageName = (string)$packageName;
                    if (isset($modulePackages[$packageName])) {
                        continue;
                    }
                    $version = null;
                    try {
                        $version = \Composer\InstalledVersions::getPrettyVersion($packageName);
                    } catch (\Throwable $exception) {
                        // Keep N/A when Composer cannot provide a version.
                    }
                    $libraries[] = [
                        'name' => $packageName,
                        'package' => $packageName,
                        'enabled' => true,
                        'version' => is_string($version) && $version !== '' ? $version : null,
                        'type' => 'composer_package',
                    ];
                }
            } catch (\Throwable $exception) {
                // Composer runtime metadata is optional; module inventory remains usable.
            }
        }

        $inventory = array_merge($modules, $libraries);
        return ['metrics' => [
            'count' => count($modules),
            'inventory_count' => count($inventory),
            'platform_count' => count(array_filter($modules, static fn(array $m): bool => $m['type'] === 'platform')),
            'custom_or_vendor_count' => count(array_filter($modules, static fn(array $m): bool => $m['type'] !== 'platform')),
            'library_count' => count($libraries),
            'package_count' => count($libraries),
            'modules' => $modules,
            'libraries' => $libraries,
            'inventory' => $inventory,
        ]];
    }
}
