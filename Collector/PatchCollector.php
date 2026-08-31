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

            $configuredPatchCount = count($patches);
            $qualityPatchesTool = $this->qualityPatchesStatus();
            $qualityPatches = $this->qualityPatchesAppliedPatches($root);
            $patches = array_merge($patches, $qualityPatches);

            return [
                'metrics' => [
                    'patch_count' => count($patches),
                    'configured_patch_count' => $configuredPatchCount,
                    'not_applied_count' => $notAppliedCount,
                    'not_verified_count' => $notVerifiedCount,
                    'applied_count' => $qualityPatchesTool['status'] === 'installed' ? count($qualityPatches) : null,
                    'application_verification' => $qualityPatchesTool['status'] === 'installed'
                        ? 'verified_from_quality_patches_tool_log'
                        : 'not_verifiable_without_patch_manager',
                    'quality_patches_tool' => $qualityPatchesTool,
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

    /**
     * Read the local Quality Patches Tool log. The tool writes an explicit success
     * record after it applies a patch, so this is stronger evidence than merely
     * finding a patch source file. The analyzer never invokes the patch tool.
     *
     * @return array<int, array<string, string>>
     */
    private function qualityPatchesAppliedPatches(string $root): array
    {
        $logFile = $root . '/var/log/patch.log';
        if (!$this->fileDriver->isExists($logFile)) {
            return [];
        }

        $states = [];
        foreach (preg_split('/\R/', $this->fileDriver->fileGetContents($logFile)) ?: [] as $line) {
            if (!preg_match('/Patch ([A-Za-z0-9_-]+) has been (applied|reverted)(?: (\{.*\}))?/', $line, $matches)) {
                continue;
            }

            $patchId = $matches[1];
            if ($matches[2] === 'reverted') {
                unset($states[$patchId]);
                continue;
            }

            $metadata = isset($matches[3]) ? json_decode($matches[3], true) : [];
            $states[$patchId] = is_array($metadata) ? (string)($metadata['file'] ?? '') : '';
        }

        $descriptions = $this->qualityPatchesDescriptions($root);
        $patches = [];
        foreach ($states as $patchId => $absolutePath) {
            $relativePath = str_replace($root . '/', '', $absolutePath);
            $patches[] = [
                'patch_id' => $patchId,
                'package' => 'magento/quality-patches',
                'description' => $descriptions[$patchId] ?? $patchId,
                'category' => $this->category($relativePath),
                'status' => 'Applied by Quality Patches Tool',
                'application_status' => 'applied_confirmed',
                'recommended' => 'Applied',
                'origin' => 'Quality Patches Tool',
                'path' => $relativePath,
                'details' => 'Application confirmed from var/log/patch.log.',
            ];
        }

        return $patches;
    }

    /** @return array<string, string> */
    private function qualityPatchesDescriptions(string $root): array
    {
        $infoFile = $root . '/vendor/magento/quality-patches/patches-info.json';
        if (!$this->fileDriver->isExists($infoFile)) {
            return [];
        }

        try {
            $data = json_decode($this->fileDriver->fileGetContents($infoFile), true, 512, JSON_THROW_ON_ERROR);
            $descriptions = [];
            foreach ($data['patches'] ?? [] as $patch) {
                if (is_array($patch) && isset($patch['id'], $patch['description'])) {
                    $descriptions[(string)$patch['id']] = (string)$patch['description'];
                }
            }
            return $descriptions;
        } catch (\Throwable $exception) {
            return [];
        }
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
