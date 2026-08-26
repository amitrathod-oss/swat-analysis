<?php
declare(strict_types=1);

namespace Asiamarket\HealthCheck\Service;

use Asiamarket\HealthCheck\Config\HealthCheckConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Resolves safe public representative URLs from the active Magento store.
 * All lookups are read-only and return only published URL paths.
 */
class RepresentativeUrlProvider
{
    private HealthCheckConfig $config;
    private ResourceConnection $resourceConnection;
    private StoreManagerInterface $storeManager;

    public function __construct(
        HealthCheckConfig $config,
        ResourceConnection $resourceConnection,
        StoreManagerInterface $storeManager
    ) {
        $this->config = $config;
        $this->resourceConnection = $resourceConnection;
        $this->storeManager = $storeManager;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    public function get(string $section, array $context = []): array
    {
        $configured = $this->config->get($section . '.urls', []);
        $configured = is_array($configured) ? $configured : [];
        $baseUrl = $this->config->resolveBaseUrl($context);
        $urls = [];

        if ($baseUrl !== '') {
            $urls['home'] = $baseUrl;
            $urls['search'] = $baseUrl . 'catalogsearch/result/?q=healthcheck';
        }

        foreach (['category', 'product', 'cms'] as $pageType) {
            $path = $this->discoverPath($pageType);
            if ($path !== null && $baseUrl !== '') {
                $urls[$pageType] = $baseUrl . ltrim($path, '/');
            }
        }

        foreach ($configured as $pageType => $url) {
            if (!is_string($url) || trim($url) === '') {
                unset($urls[(string)$pageType]);
                continue;
            }
            $url = trim($url);
            if ($url === '{auto}') {
                continue;
            }
            if ($baseUrl === '' && str_contains($url, '{base_url}')) {
                unset($urls[(string)$pageType]);
                continue;
            }
            $urls[(string)$pageType] = str_replace('{base_url}', rtrim($baseUrl, '/'), $url);
        }

        return array_filter($urls, static fn(string $url): bool => $url !== '');
    }

    private function discoverPath(string $pageType): ?string
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $storeId = (int)$this->storeManager->getStore()->getId();
            if (in_array($pageType, ['category', 'product'], true)) {
                $entityType = $pageType === 'category' ? 'category' : 'product';
                $table = $this->resourceConnection->getTableName('url_rewrite');
                $path = $connection->fetchOne(
                    'SELECT request_path FROM ' . $table
                    . ' WHERE store_id = ? AND entity_type = ? AND redirect_type = 0 '
                    . 'AND request_path IS NOT NULL AND request_path <> "" '
                    . 'ORDER BY url_rewrite_id ASC LIMIT 1',
                    [$storeId, $entityType]
                );
                return is_string($path) && $path !== '' ? $path : null;
            }

            $pageTable = $this->resourceConnection->getTableName('cms_page');
            $storeTable = $this->resourceConnection->getTableName('cms_page_store');
            $identifier = $connection->fetchOne(
                'SELECT p.identifier FROM ' . $pageTable . ' p '
                . 'INNER JOIN ' . $storeTable . ' ps ON ps.page_id = p.page_id '
                . 'WHERE p.is_active = 1 AND ps.store_id IN (0, ?) '
                . 'AND p.identifier IS NOT NULL AND p.identifier NOT IN ("no-route", "home") '
                . 'ORDER BY p.page_id ASC LIMIT 1',
                [$storeId]
            );
            return is_string($identifier) && $identifier !== '' ? $identifier : null;
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
