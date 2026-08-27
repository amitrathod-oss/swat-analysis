<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Collector;

use Magento\Framework\App\Filesystem\DirectoryList;

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
}
