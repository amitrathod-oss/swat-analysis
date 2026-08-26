<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Config;

use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Yaml\Yaml;

class HealthCheckConfig
{
    private const MODULE_NAME = 'Sigma_HealthCheck';
    private const CONFIG_FILE = 'healthcheck.yaml';

    private ModuleDirReader $moduleDirReader;
    private File $fileDriver;
    private ?StoreManagerInterface $storeManager;
    private ?ScopeConfigInterface $scopeConfig;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $config = null;

    public function __construct(
        ModuleDirReader $moduleDirReader,
        File $fileDriver,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig
    )
    {
        $this->moduleDirReader = $moduleDirReader;
        $this->fileDriver = $fileDriver;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $path, $default = null)
    {
        $value = $this->load();
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function getPositiveInt(string $path, int $default): int
    {
        $value = (int)$this->get($path, $default);
        return $value > 0 ? $value : $default;
    }

    /**
     * @return array<int, string>
     */
    public function getStringList(string $path): array
    {
        $value = $this->get($path, []);
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * Resolve the URL used by a scan from the CLI context or Magento's store configuration.
     *
     * The YAML file deliberately contains no environment-specific hostname. A configured
     * URL may use {base_url}; this is replaced at runtime with the active store URL.
     *
     * @param array<string, mixed> $context
     */
    public function resolveBaseUrl(array $context = []): string
    {
        $baseUrl = isset($context['base_url']) && is_string($context['base_url'])
            ? trim($context['base_url'])
            : '';

        if ($baseUrl === '' && $this->storeManager !== null) {
            try {
                $baseUrl = trim((string)$this->storeManager->getStore()->getBaseUrl());
            } catch (\Throwable $exception) {
                $baseUrl = '';
            }
        }

        if ($baseUrl === '') {
            return '';
        }

        return rtrim($baseUrl, '/') . '/';
    }

    public function getActiveStoreName(): string
    {
        if ($this->scopeConfig !== null) {
            try {
                $name = trim((string)$this->scopeConfig->getValue(
                    'general/store_information/name',
                    ScopeInterface::SCOPE_STORE
                ));
                if ($name !== '') {
                    return $name;
                }
            } catch (\Throwable $exception) {
                // Fall through to the store object or generic name.
            }
        }

        if ($this->storeManager !== null) {
            try {
                $name = trim((string)$this->storeManager->getStore()->getName());
                if ($name !== '') {
                    return $name;
                }
            } catch (\Throwable $exception) {
                // Fall through to the generic name.
            }
        }

        return 'Magento project';
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    public function getResolvedUrls(string $path, array $context = []): array
    {
        $value = $this->get($path, []);
        if (!is_array($value)) {
            return [];
        }

        $baseUrl = $this->resolveBaseUrl($context);
        $resolved = [];
        foreach ($value as $key => $url) {
            if (!is_string($url) || trim($url) === '') {
                continue;
            }
            $url = trim($url);
            if ($baseUrl === '' && str_contains($url, '{base_url}')) {
                continue;
            }
            $url = str_replace('{base_url}', rtrim($baseUrl, '/'), $url);
            if (str_contains($url, '{base_url}')) {
                continue;
            }
            $resolved[(string)$key] = $url;
        }

        return $resolved;
    }

    /**
     * @param mixed $default
     * @param array<string, mixed> $context
     * @return mixed
     */
    public function getResolved(string $path, $default = null, array $context = [])
    {
        $value = $this->get($path, $default);
        if (!is_string($value)) {
            return $value;
        }

        $baseUrl = $this->resolveBaseUrl($context);
        $resolved = str_replace('{base_url}', rtrim($baseUrl, '/'), $value);
        return str_contains($resolved, '{base_url}') ? $default : $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $path = $this->moduleDirReader->getModuleDir('etc', self::MODULE_NAME) . '/' . self::CONFIG_FILE;
        if (!$this->fileDriver->isExists($path)) {
            return $this->config = [];
        }

        $config = Yaml::parse($this->fileDriver->fileGetContents($path));
        if (!is_array($config)) {
            throw new \UnexpectedValueException(sprintf('Health analyzer configuration "%s" must be an array.', $path));
        }

        return $this->config = $config;
    }
}
