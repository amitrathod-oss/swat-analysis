<?php
declare(strict_types=1);

namespace Asiamarket\HealthCheck\Collector;

use Asiamarket\HealthCheck\Config\HealthCheckConfig;

class PhpCollector implements CollectorInterface
{
    private HealthCheckConfig $config;

    public function __construct(HealthCheckConfig $config)
    {
        $this->config = $config;
    }

    public function getCode(): string
    {
        return 'php';
    }

    public function isSupported(array $context = []): bool
    {
        return true;
    }

    public function collect(array $context = []): array
    {
        $opcache = null;
        if (function_exists('opcache_get_status')) {
            $status = @opcache_get_status(false);
            if (is_array($status)) {
                $memory = is_array($status['memory_usage'] ?? null) ? $status['memory_usage'] : [];
                $opcache = [
                    'enabled' => (bool)($status['opcache_enabled'] ?? false),
                    'cached_scripts' => (int)($status['opcache_statistics']['num_cached_scripts'] ?? 0),
                    'hit_rate_percent' => isset($status['opcache_statistics']['opcache_hit_rate'])
                        ? round((float)$status['opcache_statistics']['opcache_hit_rate'], 2) : null,
                    'memory_used_bytes' => $memory['used_memory'] ?? null,
                    'memory_free_bytes' => $memory['free_memory'] ?? null,
                    'restart_pending' => (bool)($status['cache_full'] ?? false),
                ];
            }
        }
        $memoryLimit = (string)ini_get('memory_limit');
        $loadedExtensions = array_map('strtolower', get_loaded_extensions());
        $requiredExtensions = array_map('strtolower', $this->config->getStringList('php.required_extensions'));
        $missingExtensions = array_values(array_diff($requiredExtensions, $loadedExtensions));
        return ['metrics' => [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'memory_limit' => $memoryLimit,
            'memory_limit_unlimited' => $memoryLimit === '-1',
            'memory_limit_review_required' => $memoryLimit === '-1' && PHP_SAPI !== 'cli',
            'max_execution_time' => (int)ini_get('max_execution_time'),
            'upload_max_filesize' => (string)ini_get('upload_max_filesize'),
            'post_max_size' => (string)ini_get('post_max_size'),
            'extensions' => get_loaded_extensions(),
            'required_extensions' => $requiredExtensions,
            'missing_required_extensions' => $missingExtensions,
            'opcache' => $opcache,
        ]];
    }
}
