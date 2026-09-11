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
            $validation = $this->commandRunner->validate($workingDirectory, $timeout);
            $audit = $this->commandRunner->audit($workingDirectory, $timeout);
            $auditData = json_decode((string)$audit['output'], true);
            if (!is_array($auditData)) {
                throw new \RuntimeException('Composer audit did not return JSON.');
            }
            $advisories = is_array($auditData['advisories'] ?? null) ? $auditData['advisories'] : [];
            $abandoned = is_array($auditData['abandoned'] ?? null) ? $auditData['abandoned'] : [];
            $affectedPackages = [];
            $advisoryDetails = [];
            $vulnerabilityCount = 0;
            ksort($advisories);
            foreach ($advisories as $package => $packageAdvisories) {
                $count = is_array($packageAdvisories) ? count($packageAdvisories) : 0;
                $affectedPackages[(string)$package] = $count;
                $vulnerabilityCount += $count;
                foreach (is_array($packageAdvisories) ? $packageAdvisories : [] as $advisory) {
                    if (!is_array($advisory)) continue;
                    $advisoryDetails[] = [
                        'package' => (string)$package,
                        'advisory_id' => (string)($advisory['advisoryId'] ?? $advisory['advisory_id'] ?? 'Not provided'),
                        'severity' => (string)($advisory['severity'] ?? 'Not provided'),
                        'cve' => (string)($advisory['cve'] ?? 'Not provided'),
                        'title' => (string)($advisory['title'] ?? 'Not provided'),
                        'affected_versions' => (string)($advisory['affectedVersions'] ?? $advisory['affected_versions'] ?? 'Not provided'),
                        'reported_at' => (string)($advisory['reportedAt'] ?? $advisory['reported_at'] ?? 'Not provided'),
                        'url' => (string)($advisory['link'] ?? $advisory['url'] ?? ''),
                    ];
                }
            }
            ksort($affectedPackages);
            usort($advisoryDetails, static function (array $left, array $right): int {
                return [$left['package'], $left['advisory_id']] <=> [$right['package'], $right['advisory_id']];
            });
            $lockFile = rtrim($workingDirectory, '/') . '/composer.lock';
            $jsonFile = rtrim($workingDirectory, '/') . '/composer.json';

            return [
                'metrics' => [
                    'version' => trim((string)$version['output']),
                    'validation_exit_code' => (int)$validation['exit_code'],
                    'audit_exit_code' => (int)$audit['exit_code'],
                    'vulnerability_count' => $vulnerabilityCount,
                    'affected_packages' => $affectedPackages,
                    'advisories' => $advisoryDetails,
                    'audit_command' => 'composer audit --locked --format=json --no-interaction --no-ansi',
                    'audit_basis' => 'composer.lock',
                    'working_directory' => $workingDirectory,
                    'composer_lock_sha256' => is_file($lockFile) ? hash_file('sha256', $lockFile) : null,
                    'composer_json_sha256' => is_file($jsonFile) ? hash_file('sha256', $jsonFile) : null,
                    'audit_checked_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
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
