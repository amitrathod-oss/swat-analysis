<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Collector;

use Sigma\HealthCheck\Config\HealthCheckConfig;
use Magento\Framework\App\Filesystem\DirectoryList;

class SecurityCollector implements CollectorInterface
{
    private DirectoryList $directoryList;
    private HealthCheckConfig $config;

    public function __construct(DirectoryList $directoryList, HealthCheckConfig $config)
    {
        $this->directoryList = $directoryList;
        $this->config = $config;
    }

    public function getCode(): string
    {
        return 'security';
    }

    public function isSupported(array $context = []): bool
    {
        return true;
    }

    public function collect(array $context = []): array
    {
        $root = (string)($context['magento_root'] ?? $this->directoryList->getRoot());
        $candidates = [
            '.env' => $root . '/.env',
            'pub/.env' => $root . '/pub/.env',
            'pub/auth.json' => $root . '/pub/auth.json',
            'pub/composer-auth.json' => $root . '/pub/composer-auth.json',
            'phpinfo.php' => $root . '/pub/phpinfo.php',
        ];
        $exposed = [];
        foreach ($candidates as $name => $path) {
            if (is_file($path) || is_dir($path)) {
                $exposed[] = $name;
            }
        }
        $permissions = [];
        foreach (['app/etc/env.php', 'app/etc/config.php'] as $relative) {
            $path = $root . '/' . $relative;
            if (is_file($path)) {
                $mode = @fileperms($path);
                $permissions[$relative] = [
                    'octal' => $mode === false ? null : substr(sprintf('%o', $mode), -4),
                    'world_writable' => $mode !== false && (($mode & 2) !== 0),
                ];
            }
        }
        return ['metrics' => [
            'sensitive_paths_present' => $exposed,
            'sensitive_path_count' => count($exposed),
            'permissions' => $permissions,
            'tls' => $this->tlsSummary($this->config->resolveBaseUrl($context)),
        ]];
    }

    /** @return array<string, mixed> */
    private function tlsSummary(string $url): array
    {
        if ($url === '' || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return ['status' => 'not_https', 'days_to_expiry' => null];
        }
        $host = (string)parse_url($url, PHP_URL_HOST);
        $port = (int)(parse_url($url, PHP_URL_PORT) ?: 443);
        if ($host === '') {
            return ['status' => 'invalid_host', 'days_to_expiry' => null];
        }
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ]]);
        $client = @stream_socket_client('ssl://' . $host . ':' . $port, $errorNumber, $error, 5, STREAM_CLIENT_CONNECT, $context);
        if ($client === false) {
            return ['status' => 'unavailable', 'days_to_expiry' => null];
        }
        $params = stream_context_get_params($client);
        fclose($client);
        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        if ($certificate === null || !function_exists('openssl_x509_parse')) {
            return ['status' => 'unavailable', 'days_to_expiry' => null];
        }
        $parsed = @openssl_x509_parse($certificate);
        $expiry = (int)($parsed['validTo_time_t'] ?? 0);
        return [
            'status' => $expiry > time() ? 'valid' : 'expired',
            'days_to_expiry' => $expiry > 0 ? (int)floor(($expiry - time()) / 86400) : null,
            'subject' => (string)($parsed['subject']['CN'] ?? ''),
        ];
    }
}
