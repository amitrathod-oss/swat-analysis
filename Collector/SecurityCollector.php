<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Collector;

use Mha\HealthCheck\Config\HealthCheckConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\HTTP\Client\CurlFactory;

class SecurityCollector implements CollectorInterface
{
    private DirectoryList $directoryList;
    private HealthCheckConfig $config;
    private ResourceConnection $resourceConnection;
    private CurlFactory $curlFactory;
    private DeploymentConfig $deploymentConfig;

    public function __construct(
        DirectoryList $directoryList,
        HealthCheckConfig $config,
        ResourceConnection $resourceConnection,
        CurlFactory $curlFactory,
        DeploymentConfig $deploymentConfig
    )
    {
        $this->directoryList = $directoryList;
        $this->config = $config;
        $this->resourceConnection = $resourceConnection;
        $this->curlFactory = $curlFactory;
        $this->deploymentConfig = $deploymentConfig;
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
            'admin_roles' => $this->adminRoles(),
            'admin_security_config' => $this->adminSecurityConfig(),
            'cookie_secure_config' => $this->cookieSecureConfig(),
            'error_disclosure' => $this->errorDisclosure($this->config->resolveBaseUrl($context)),
            'suspicious_code' => $this->suspiciousCode($root),
            'catalogue_configuration' => $this->catalogueConfiguration(),
            'deployment_configuration' => $this->deploymentConfiguration(),
            'database_privileges' => $this->databasePrivileges(),
            'oauth_integrations' => $this->oauthIntegrations(),
            'sensitive_file_permissions' => $this->sensitiveFilePermissions($root),
        ]];
    }

    /** @return array<string, mixed> */
    private function deploymentConfiguration(): array
    {
        try {
            $session = $this->deploymentConfig->get('session', []);
            $defaultCache = $this->deploymentConfig->get('cache/frontend/default', []);
            $pageCache = $this->deploymentConfig->get('cache/frontend/page_cache', []);
            $database = $this->deploymentConfig->get('db/connection/default', []);
            return ['status' => 'success', 'session_backend' => is_array($session) ? (string)($session['save'] ?? 'files') : 'files', 'default_cache_backend' => is_array($defaultCache) ? (string)($defaultCache['backend'] ?? '') : '', 'page_cache_backend' => is_array($pageCache) ? (string)($pageCache['backend'] ?? '') : '', 'database_configured' => is_array($database) && (string)($database['host'] ?? '') !== '', 'database_has_port' => is_array($database) && isset($database['port'])];
        } catch (\Throwable $exception) {
            return ['status' => 'not_checked', 'reason' => 'Deployment configuration could not be read.'];
        }
    }

    /** @return array<string, mixed> */
    private function catalogueConfiguration(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $connection->quoteIdentifier($this->resourceConnection->getTableName('core_config_data'));
            $patterns = ['system/full_page_cache/%', 'catalog/search/%', 'tax/%', 'carriers/%/active', 'payment/%', 'customer/%', 'cataloginventory/%', 'dev/%'];
            $where = implode(' OR ', array_map(static fn(string $pattern): string => 'path LIKE ' . $connection->quote($pattern), $patterns));
            $rows = $connection->fetchAll('SELECT scope, scope_id, path, value FROM ' . $table . ' WHERE ' . $where);
            $values = [];
            foreach ($rows as $row) {
                $path = (string)($row['path'] ?? '');
                $values[] = ['scope' => (string)($row['scope'] ?? 'default'), 'scope_id' => (int)($row['scope_id'] ?? 0), 'path' => $path, 'value' => $this->safeConfigValue($path, (string)($row['value'] ?? ''))];
            }
            return ['status' => 'success', 'values' => $values];
        } catch (\Throwable $exception) {
            return ['status' => 'not_checked', 'reason' => 'Catalogue configuration could not be read with the configured database access.'];
        }
    }

    private function safeConfigValue(string $path, string $value): string
    {
        return preg_match('/(?:password|secret|token|api[_-]?key|private)/i', $path) === 1 ? '[redacted]' : $value;
    }

    /** @return array<string, mixed> */
    private function databasePrivileges(): array
    {
        try {
            $rows = $this->resourceConnection->getConnection()->fetchAll('SELECT GRANTEE, PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.user_privileges WHERE GRANTEE = CURRENT_USER()');
            $dangerous = [];
            foreach ($rows as $row) {
                $privilege = strtoupper((string)($row['PRIVILEGE_TYPE'] ?? $row['privilege_type'] ?? ''));
                if (in_array($privilege, ['FILE', 'SUPER', 'GRANT OPTION', 'SHUTDOWN', 'PROCESS'], true) || strtoupper((string)($row['IS_GRANTABLE'] ?? $row['is_grantable'] ?? 'NO')) === 'YES') $dangerous[] = $privilege;
            }
            return ['status' => 'success', 'dangerous_privileges' => array_values(array_unique($dangerous))];
        } catch (\Throwable $exception) {
            return ['status' => 'not_checked', 'reason' => 'Database global privileges are not available to the configured account.'];
        }
    }

    /** @return array<string, mixed> */
    private function oauthIntegrations(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $connection->quoteIdentifier($this->resourceConnection->getTableName('integration'));
            $rows = $connection->fetchAll('SELECT integration_id, name, status, consumer_id FROM ' . $table);
            $active = array_values(array_filter(array_map(static fn(array $row): array => ['id' => (int)($row['integration_id'] ?? 0), 'name' => (string)($row['name'] ?? ''), 'status' => (int)($row['status'] ?? 0)], $rows), static fn(array $row): bool => $row['status'] === 1));
            return ['status' => 'success', 'active' => $active, 'count' => count($rows)];
        } catch (\Throwable $exception) {
            return ['status' => 'not_checked', 'reason' => 'OAuth integration metadata could not be read.'];
        }
    }

    /** @return array<string, mixed> */
    private function sensitiveFilePermissions(string $root): array
    {
        $matches = [];
        foreach (['var/backups', 'var/log'] as $relative) {
            $path = $root . '/' . $relative;
            if (!is_dir($path)) continue;
            try {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isFile() && ($file->getPerms() & 0004) !== 0) $matches[] = ['path' => $relative . '/' . substr($file->getPathname(), strlen(rtrim($path, '/')) + 1), 'mode' => substr(sprintf('%o', $file->getPerms()), -4)];
                }
            } catch (\UnexpectedValueException $exception) {
                return ['status' => 'not_checked', 'reason' => 'Backup or log file permissions could not be inspected.'];
            }
        }
        return ['status' => 'success', 'world_readable' => $matches];
    }

    /** @return array<string, mixed> */
    private function adminRoles(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $users = $connection->quoteIdentifier($this->resourceConnection->getTableName('admin_user'));
            $roles = $connection->quoteIdentifier($this->resourceConnection->getTableName('authorization_role'));
            $rules = $connection->quoteIdentifier($this->resourceConnection->getTableName('authorization_rule'));
            $rows = $connection->fetchAll(
                'SELECT u.username, r.role_name, GROUP_CONCAT(DISTINCT ru.resource_id) AS resources '
                . 'FROM ' . $users . ' u JOIN ' . $roles . ' r ON r.user_id = u.user_id AND r.role_type = "U" '
                . 'LEFT JOIN ' . $rules . ' ru ON ru.role_id = r.parent_id '
                . 'GROUP BY u.user_id, u.username, r.role_name'
            );
            $unrestricted = [];
            foreach ($rows as $row) {
                $resources = (string)($row['resources'] ?? '');
                if (str_contains($resources, 'Magento_Backend::all')) {
                    $unrestricted[] = ['username' => (string)($row['username'] ?? ''), 'role' => (string)($row['role_name'] ?? '')];
                }
            }
            return ['status' => 'success', 'unrestricted' => $unrestricted, 'role_count' => count($rows)];
        } catch (\Throwable $exception) {
            return ['status' => 'not_checked', 'reason' => 'Admin-role data could not be read with the configured database access.'];
        }
    }

    /** @return array<string, mixed> */
    private function adminSecurityConfig(): array
    {
        return $this->configurationValues([
            'admin/security/lockout_failures',
            'admin/security/lockout_threshold',
            'admin/security/session_lifetime',
            'admin/security/use_form_key',
            'admin/security/password_is_forced',
            'admin/url/use_custom',
        ]);
    }

    /** @return array<string, mixed> */
    private function cookieSecureConfig(): array
    {
        return $this->configurationValues(['web/cookie/cookie_secure']);
    }

    /** @param string[] $paths @return array<string, mixed> */
    private function configurationValues(array $paths): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $connection->quoteIdentifier($this->resourceConnection->getTableName('core_config_data'));
            $quotedPaths = implode(', ', array_map([$connection, 'quote'], $paths));
            $rows = $connection->fetchAll('SELECT scope, scope_id, path, value FROM ' . $table . ' WHERE path IN (' . $quotedPaths . ')');
            return ['status' => 'success', 'values' => array_map(static fn(array $row): array => [
                'scope' => (string)($row['scope'] ?? 'default'),
                'scope_id' => (int)($row['scope_id'] ?? 0),
                'path' => (string)($row['path'] ?? ''),
                'value' => (string)($row['value'] ?? ''),
            ], $rows)];
        } catch (\Throwable $exception) {
            return ['status' => 'not_checked', 'reason' => 'Magento configuration values could not be read with the configured database access.'];
        }
    }

    /** @return array<string, mixed> */
    private function errorDisclosure(string $baseUrl): array
    {
        if ($baseUrl === '' || !in_array((string)parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return ['status' => 'not_checked', 'reason' => 'No valid public base URL is configured.'];
        }
        try {
            $url = rtrim($baseUrl, '/') . '/__mha_health_check_missing_' . bin2hex(random_bytes(8));
            $client = $this->curlFactory->create();
            $client->setTimeout($this->config->getPositiveInt('scan.http_timeout_seconds', 10));
            $client->addHeader('User-Agent', 'Magento-HealthCheck/1.0');
            $client->get($url);
            $body = substr((string)$client->getBody(), 0, 1048576);
            $patterns = [
                'stack_trace' => '/(?:stack trace|#0\s+[^\n]+\.php)/i',
                'filesystem_path' => '/(?:\/var\/www\/|[A-Z]:\\\\[^\s<]+)/i',
                'sqlstate' => '/SQLSTATE\[[A-Z0-9]+\]/i',
                'exception_class' => '/(?:Magento|Zend|Exception)\\\\[A-Za-z0-9_\\\\]+(?:Exception|Error)/',
            ];
            $indicators = [];
            foreach ($patterns as $name => $pattern) {
                if (preg_match($pattern, $body) === 1) {
                    $indicators[] = $name;
                }
            }
            return ['status' => 'success', 'http_status' => $client->getStatus(), 'indicators' => $indicators];
        } catch (\Throwable $exception) {
            return ['status' => 'not_checked', 'reason' => 'The public error response could not be inspected.'];
        }
    }

    /** @return array<string, mixed> */
    private function suspiciousCode(string $root): array
    {
        $matches = [];
        $inspected = 0;
        $limit = 20000;
        $pattern = '/\\b(base64_decode|eval|gzinflate|shell_exec|passthru|system)\\s*\\(/';
        foreach (['app/code', 'pub', 'var', 'generated'] as $relative) {
            $path = $root . '/' . $relative;
            if (!is_dir($path)) {
                continue;
            }
            try {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if (++$inspected > $limit) {
                        return ['status' => 'truncated', 'matches' => $matches, 'inspected_files' => $inspected - 1, 'limit' => $limit];
                    }
                    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php' || $file->getSize() > 2097152) {
                        continue;
                    }
                    $contents = @file_get_contents($file->getPathname());
                    if ($contents !== false && preg_match_all($pattern, $contents, $found) > 0) {
                        $matches[] = ['path' => substr($file->getPathname(), strlen(rtrim($root, '/')) + 1), 'tokens' => array_values(array_unique($found[1]))];
                    }
                }
            } catch (\UnexpectedValueException $exception) {
                return ['status' => 'not_checked', 'reason' => 'A PHP path could not be read safely.', 'matches' => $matches];
            }
        }
        return ['status' => 'success', 'matches' => $matches, 'inspected_files' => $inspected, 'limit' => $limit];
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
