<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Collector;

use Mha\HealthCheck\Config\HealthCheckConfig;
use Mha\HealthCheck\Service\ComposerCommandRunner;
use Magento\Framework\App\Filesystem\DirectoryList;

class ComposerCollector implements CollectorInterface
{
    private ComposerCommandRunner $commandRunner;
    private DirectoryList $directoryList;
    private HealthCheckConfig $config;

    public function __construct(
        ComposerCommandRunner $commandRunner,
        DirectoryList $directoryList,
        HealthCheckConfig $config
    ) {
        $this->commandRunner = $commandRunner;
        $this->directoryList = $directoryList;
        $this->config = $config;
    }

    public function getCode(): string
    {
        return 'composer';
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
        try {
            $workingDirectory = $this->directoryList->getRoot();
            $timeout = $this->config->getPositiveInt('scan.command_timeout_seconds', 30);
            $version = $this->commandRunner->version($workingDirectory, $timeout);
            $audit = $this->commandRunner->audit($workingDirectory, $timeout);
            $auditData = json_decode((string)$audit['output'], true);
            if (!is_array($auditData)) {
                throw new \RuntimeException('Composer audit did not return JSON.');
            }
            $advisories = is_array($auditData['advisories'] ?? null) ? $auditData['advisories'] : [];
            $abandoned = is_array($auditData['abandoned'] ?? null) ? $auditData['abandoned'] : [];
            $affectedPackages = [];
            $vulnerabilityCount = 0;
            foreach ($advisories as $package => $packageAdvisories) {
                $count = is_array($packageAdvisories) ? count($packageAdvisories) : 0;
                $affectedPackages[(string)$package] = $count;
                $vulnerabilityCount += $count;
            }

            return [
                'metrics' => [
                    'version' => trim((string)$version['output']),
                    'audit_exit_code' => (int)$audit['exit_code'],
                    'vulnerability_count' => $vulnerabilityCount,
                    'affected_packages' => $affectedPackages,
                    'abandoned_packages' => array_keys($abandoned),
                    'abandoned_package_count' => count($abandoned),
                ],
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'unavailable',
                'message' => 'Composer audit could not be run.',
                'metrics' => [],
            ];
        }
    }
}
