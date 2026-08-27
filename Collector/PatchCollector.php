<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Collector;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;

class PatchCollector implements CollectorInterface
{
    private DirectoryList $directoryList;
    private File $fileDriver;

    public function __construct(DirectoryList $directoryList, File $fileDriver)
    {
        $this->directoryList = $directoryList;
        $this->fileDriver = $fileDriver;
    }

    public function getCode(): string
    {
        return 'patches';
    }

    public function isSupported(array $context = []): bool
    {
        return true;
    }

    /**
     * Read Composer patch declarations and verify that local patch files exist.
     * This does not apply, reapply, or modify any patch.
     *
     * @return array<string, mixed>
     */
    public function collect(array $context = []): array
    {
        $composerFile = rtrim($this->directoryList->getRoot(), '/') . '/composer.json';
        if (!$this->fileDriver->isExists($composerFile)) {
            return [
                'status' => 'not_applicable',
                'message' => 'composer.json was not found.',
                'metrics' => ['patches' => [], 'patch_count' => 0],
            ];
        }

        try {
            $composer = json_decode($this->fileDriver->fileGetContents($composerFile), true, 512, JSON_THROW_ON_ERROR);
            $configured = $composer['extra']['patches'] ?? [];
            if (!is_array($configured)) {
                $configured = [];
            }
            $root = rtrim($this->directoryList->getRoot(), '/');
            $patches = [];
            $notAppliedCount = 0;
            $notVerifiedCount = 0;
            foreach ($configured as $package => $packagePatches) {
                if (!is_array($packagePatches)) {
                    continue;
                }
                foreach ($packagePatches as $description => $path) {
                    if (!is_string($path)) {
                        continue;
                    }
                    $relativePath = ltrim($path, '/');
                    $absolutePath = $root . '/' . $relativePath;
                    $exists = $this->fileDriver->isExists($absolutePath);
                    $applicationStatus = $exists ? 'not_verified' : 'not_applied';
                    if ($exists) {
                        $notVerifiedCount++;
                    } else {
                        $notAppliedCount++;
                    }
                    $patches[] = [
                        'patch_id' => $this->patchId($path),
                        'package' => (string)$package,
                        'description' => (string)$description,
                        'category' => $this->category($path),
                        'status' => $exists ? 'Configured; application not verified' : 'Not applied; source file missing',
                        'application_status' => $applicationStatus,
                        'recommended' => 'Review',
                        'origin' => 'Composer extra.patches',
                        'path' => $relativePath,
                        'details' => $exists
                            ? 'Patch source file is present, but source presence does not prove that the patch was applied. Use the configured patch manager to verify application.'
                            : 'Patch is configured but its source file was not found, so it cannot be applied.' ,
                    ];
                }
            }

            return [
                'metrics' => [
                    'patch_count' => count($patches),
                    'configured_patch_count' => count($patches),
                    'not_applied_count' => $notAppliedCount,
                    'not_verified_count' => $notVerifiedCount,
                    'applied_count' => null,
                    'application_verification' => 'not_verifiable_without_patch_manager',
                    'quality_patches_tool' => $this->qualityPatchesStatus(),
                    'patches' => $patches,
                ],
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'unavailable',
                'message' => 'Composer patch metadata could not be read.',
                'metrics' => ['patches' => [], 'patch_count' => 0],
            ];
        }
    }

    /**
     * Report whether the optional Quality Patches Tool is installed; never invoke it.
     * Adobe's remote QPT recommendation feed is not available to this local analyzer.
     *
     * @return array<string, string|bool>
     */
    private function qualityPatchesStatus(): array
    {
        $root = rtrim($this->directoryList->getRoot(), '/');
        $paths = [$root . '/vendor/bin/magento-patches', $root . '/vendor/bin/magento-quality-patches'];
        foreach ($paths as $path) {
            if ($this->fileDriver->isExists($path)) {
                return [
                    'status' => 'installed',
                    'path' => str_replace($root . '/', '', $path),
                    'recommendations_available' => false,
                ];
            }
        }

        return [
            'status' => 'not_installed',
            'path' => '',
            'recommendations_available' => false,
        ];
    }

    private function patchId(string $path): string
    {
        return strtoupper((string)pathinfo($path, PATHINFO_FILENAME));
    }

    private function category(string $path): string
    {
        $lowerPath = strtolower($path);
        if (str_contains($lowerPath, 'security') || str_contains($lowerPath, 'mdva')) {
            return 'Security';
        }
        if (str_contains($lowerPath, 'hotfix')) {
            return 'Hotfix';
        }

        return 'Custom';
    }
}
