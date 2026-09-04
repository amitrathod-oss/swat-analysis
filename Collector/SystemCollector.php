<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Collector;

use Magento\Framework\App\Filesystem\DirectoryList;
use Symfony\Component\Process\Process;

class SystemCollector implements CollectorInterface
{
    private DirectoryList $directoryList;

    public function __construct(DirectoryList $directoryList)
    {
        $this->directoryList = $directoryList;
    }

    public function getCode(): string
    {
        return 'system';
    }

    public function isSupported(array $context = []): bool
    {
        return function_exists('disk_total_space');
    }

    public function collect(array $context = []): array
    {
        $root = (string)($context['magento_root'] ?? $this->directoryList->getRoot());
        $total = @disk_total_space($root);
        $free = @disk_free_space($root);
        $metrics = [
            'php_uname' => php_uname(),
            'cpu_count' => $this->cpuCount(),
            'load_average' => $this->loadAverage(),
            'memory' => $this->memoryInfo(),
            'operating_system' => $this->operatingSystem(),
            'web_server' => $this->webServer(),
            'writable_directories' => $this->writableDirectories($root),
            'inode' => $this->inodeUsage($root),
            'unsafe_scripts' => $this->unsafeScripts($root),
            'disk' => [
                'path' => $root,
                'total_bytes' => $total === false ? null : (int)$total,
                'free_bytes' => $free === false ? null : (int)$free,
                'used_percent' => $total && $free !== false ? round((($total - $free) / $total) * 100, 2) : null,
            ],
            'read_only_probe' => true,
        ];

        return ['metrics' => $metrics];
    }

    private function cpuCount(): ?int
    {
        $contents = @file_get_contents('/proc/cpuinfo');
        if ($contents === false) {
            return null;
        }
        return max(1, substr_count($contents, "processor\t:"));
    }

    /** @return array<string, float>|null */
    private function loadAverage(): ?array
    {
        $contents = @file_get_contents('/proc/loadavg');
        if ($contents === false) {
            return function_exists('sys_getloadavg') ? array_map('floatval', sys_getloadavg()) : null;
        }
        $parts = preg_split('/\s+/', trim($contents));
        return count($parts) >= 3 ? ['one' => (float)$parts[0], 'five' => (float)$parts[1], 'fifteen' => (float)$parts[2]] : null;
    }

    /** @return array<string, int|float|null> */
    private function memoryInfo(): array
    {
        $contents = @file_get_contents('/proc/meminfo');
        if ($contents === false) {
            return ['total_bytes' => null, 'available_bytes' => null, 'used_percent' => null];
        }
        $values = [];
        foreach (['MemTotal', 'MemAvailable'] as $name) {
            if (preg_match('/^' . $name . ':\s+(\d+)\s+kB$/m', $contents, $match)) {
                $values[$name] = (int)$match[1] * 1024;
            }
        }
        $total = $values['MemTotal'] ?? null;
        $available = $values['MemAvailable'] ?? null;
        return [
            'total_bytes' => $total,
            'available_bytes' => $available,
            'used_percent' => $total && $available !== null ? round((($total - $available) / $total) * 100, 2) : null,
        ];
    }

    /** @return array<string, string> */
    private function operatingSystem(): array
    {
        $contents = @file_get_contents('/etc/os-release');
        if ($contents === false) return ['status' => 'not_checked', 'reason' => 'The OS release file is not readable.'];
        $values = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $match)) $values[strtolower($match[1])] = trim($match[2], "\"'");
        }
        return ['status' => 'success', 'id' => (string)($values['id'] ?? 'unknown'), 'version_id' => (string)($values['version_id'] ?? ''), 'name' => (string)($values['pretty_name'] ?? $values['name'] ?? 'unknown')];
    }

    /** @return array<string, string> */
    private function webServer(): array
    {
        foreach ([['nginx', '-v'], ['apachectl', '-v'], ['caddy', 'version']] as $command) {
            try {
                $process = new Process($command, null, null, null, 2);
                $process->run();
                $output = trim($process->getOutput() . "\n" . $process->getErrorOutput());
                if ($process->isSuccessful() && preg_match('/([0-9]+\.[0-9]+(?:\.[0-9]+)?)/', $output, $match)) return ['status' => 'success', 'binary' => $command[0], 'version' => $match[1]];
            } catch (\Throwable $exception) { continue; }
        }
        return ['status' => 'not_checked', 'reason' => 'No allow-listed nginx, Apache, or Caddy binary was detected.'];
    }

    /** @return array<string, array<string, mixed>> */
    private function writableDirectories(string $root): array
    {
        $directories = ['app/etc', 'var', 'pub/static', 'generated'];
        $result = [];
        foreach ($directories as $relative) {
            $path = $root . '/' . $relative;
            $mode = @fileperms($path);
            $result[$relative] = [
                'exists' => is_dir($path),
                'writable' => is_dir($path) && is_writable($path),
                'octal' => $mode === false ? null : substr(sprintf('%o', $mode), -4),
                'world_writable' => $mode !== false && (($mode & 0002) !== 0),
            ];
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function inodeUsage(string $root): array
    {
        try {
            $process = new Process(['df', '-Pi', $root], null, null, null, 2);
            $process->run();
            $lines = preg_split('/\R/', trim($process->getOutput())) ?: [];
            $line = $lines[count($lines) - 1] ?? '';
            if (!$process->isSuccessful() || preg_match('/\s(\d+)%\s+\S+$/', $line, $match) !== 1) {
                return ['status' => 'not_checked', 'reason' => 'Inode utilization is not available from df -Pi.'];
            }
            return ['status' => 'success', 'used_percent' => (int)$match[1], 'path' => $root];
        } catch (\Throwable $exception) {
            return ['status' => 'not_checked', 'reason' => 'Inode utilization could not be inspected.'];
        }
    }

    /**
     * Read-only, bounded scan for group/world-writable PHP or shell scripts.
     * @return array<string, mixed>
     */
    private function unsafeScripts(string $root): array
    {
        $hits = [];
        $inspected = 0;
        $limit = 20000;
        foreach (['app', 'bin', 'pub', 'var'] as $relative) {
            $path = $root . '/' . $relative;
            if (!is_dir($path)) {
                continue;
            }
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if (++$inspected > $limit) {
                        return ['status' => 'truncated', 'matches' => $hits, 'inspected_files' => $inspected - 1, 'limit' => $limit];
                    }
                    if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['php', 'sh'], true)) {
                        continue;
                    }
                    $mode = $file->getPerms();
                    if (($mode & 0022) !== 0) {
                        $hits[] = ['path' => substr($file->getPathname(), strlen(rtrim($root, '/')) + 1), 'mode' => substr(sprintf('%o', $mode), -4)];
                    }
                }
            } catch (\UnexpectedValueException $exception) {
                return ['status' => 'not_checked', 'reason' => 'A filesystem path could not be read safely.', 'matches' => $hits];
            }
        }
        return ['status' => 'success', 'matches' => $hits, 'inspected_files' => $inspected, 'limit' => $limit];
    }
}
