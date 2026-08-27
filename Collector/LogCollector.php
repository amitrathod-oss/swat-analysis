<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Collector;

use Mha\HealthCheck\Config\HealthCheckConfig;
use Mha\HealthCheck\Log\ExceptionFingerprint;
use Mha\HealthCheck\Security\SecretSanitizer;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;

class LogCollector implements CollectorInterface
{
    private DirectoryList $directoryList;
    private File $fileDriver;
    private ExceptionFingerprint $fingerprint;
    private SecretSanitizer $secretSanitizer;
    private HealthCheckConfig $config;

    public function __construct(
        DirectoryList $directoryList,
        File $fileDriver,
        ExceptionFingerprint $fingerprint,
        SecretSanitizer $secretSanitizer,
        HealthCheckConfig $config
    ) {
        $this->directoryList = $directoryList;
        $this->fileDriver = $fileDriver;
        $this->fingerprint = $fingerprint;
        $this->secretSanitizer = $secretSanitizer;
        $this->config = $config;
    }

    public function getCode(): string
    {
        return 'logs';
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
        $files = [
            'exception.log' => $this->directoryList->getPath(DirectoryList::LOG) . '/exception.log',
            'system.log' => $this->directoryList->getPath(DirectoryList::LOG) . '/system.log',
        ];
        $exceptions = [];
        $fileStates = [];
        $cutoff = (new \DateTimeImmutable())->sub(
            new \DateInterval('PT' . $this->config->getPositiveInt('scan.log_window_hours', 24) . 'H')
        );
        foreach ($files as $name => $path) {
            if (!$this->fileDriver->isExists($path)) {
                $fileStates[$name] = 'missing';
                continue;
            }
            try {
                $fileStates[$name] = 'read';
                foreach ($this->extractEntries($this->readTail($path), $name, $cutoff) as $entry) {
                    $fingerprint = $entry['fingerprint'];
                    if (!isset($exceptions[$fingerprint])) {
                        $exceptions[$fingerprint] = $entry + ['count' => 0];
                    }
                    $exceptions[$fingerprint]['count']++;
                    $exceptions[$fingerprint]['first_seen'] = min(
                        (string)$exceptions[$fingerprint]['first_seen'],
                        (string)$entry['first_seen']
                    );
                    $exceptions[$fingerprint]['last_seen'] = max(
                        (string)$exceptions[$fingerprint]['last_seen'],
                        (string)$entry['last_seen']
                    );
                }
            } catch (\Throwable $exception) {
                $fileStates[$name] = 'unavailable';
            }
        }

        $repeatedOpenSearch = 0;
        $customModuleWarnings = 0;
        foreach ($exceptions as $exception) {
            if ($exception['count'] > 1 && stripos((string)$exception['exception_type'], 'OpenSearch') !== false) {
                $repeatedOpenSearch++;
            }
            if (str_starts_with((string)$exception['source'], 'app/code/')
                && stripos((string)$exception['sample'], 'WARNING') !== false) {
                $customModuleWarnings++;
            }
        }

        return [
            'metrics' => [
                'files' => $fileStates,
                'exception_count' => count($exceptions),
                'exceptions' => $exceptions,
                'repeated_opensearch_exception_count' => $repeatedOpenSearch,
                'custom_module_warning_count' => $customModuleWarnings,
            ],
        ];
    }

    private function readTail(string $path): string
    {
        $maxBytes = $this->config->getPositiveInt('scan.log_max_bytes_per_file', 1048576);
        $size = (int)($this->fileDriver->stat($path)['size'] ?? 0);
        $resource = $this->fileDriver->fileOpen($path, 'rb');
        try {
            if ($size > $maxBytes) {
                $this->fileDriver->fileSeek($resource, -$maxBytes, SEEK_END);
            }
            $content = $this->fileDriver->fileRead($resource, $maxBytes);
        } finally {
            $this->fileDriver->fileClose($resource);
        }

        return $size > $maxBytes ? (string)strstr($content, "\n") : $content;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function extractEntries(string $content, string $sourceFile, \DateTimeImmutable $cutoff): array
    {
        $entries = preg_split('/(?=^\[[^\]]+\]\s+)/m', $content) ?: [];
        $results = [];
        foreach ($entries as $entry) {
            if (!preg_match('/(?:Exception|Error|CRITICAL|WARNING)/i', $entry)) {
                continue;
            }
            $timestamp = $this->extractTimestamp($entry);
            if ($timestamp !== null && $timestamp < $cutoff) {
                continue;
            }
            $type = $this->extractExceptionType($entry);
            $source = $this->extractSource($entry, $sourceFile);
            $safeEntry = (string)$this->secretSanitizer->sanitize(substr($entry, 0, 2000));
            $results[] = [
                'fingerprint' => $this->fingerprint->create($entry),
                'exception_type' => $type,
                'first_seen' => $timestamp?->format(\DateTimeInterface::ATOM) ?? 'unknown',
                'last_seen' => $timestamp?->format(\DateTimeInterface::ATOM) ?? 'unknown',
                'source' => $source,
                'sample' => trim($safeEntry),
            ];
        }

        return $results;
    }

    private function extractTimestamp(string $entry): ?\DateTimeImmutable
    {
        if (!preg_match('/^\[([^\]]+)\]/', $entry, $matches)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($matches[1]);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function extractExceptionType(string $entry): string
    {
        if (preg_match('/([A-Za-z_][A-Za-z0-9_\\\\]+(?:Exception|Error))/', $entry, $matches)) {
            return $matches[1];
        }

        return 'Application log entry';
    }

    private function extractSource(string $entry, string $sourceFile): string
    {
        if (preg_match('#(app/code/[^/]+/[^/]+)#', $entry, $matches)) {
            return $matches[1];
        }

        return 'var/log/' . $sourceFile;
    }
}
