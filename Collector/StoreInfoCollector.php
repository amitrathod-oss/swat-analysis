<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Collector;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class StoreInfoCollector implements CollectorInterface
{
    private ScopeConfigInterface $scopeConfig;
    private StoreManagerInterface $storeManager;

    public function __construct(ScopeConfigInterface $scopeConfig, StoreManagerInterface $storeManager)
    {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
    }

    public function getCode(): string
    {
        return 'store';
    }

    public function isSupported(array $context = []): bool
    {
        return true;
    }

    public function collect(array $context = []): array
    {
        $stores = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            $storeId = (int)$store->getId();
            $stores[] = [
                'id' => $storeId,
                'code' => (string)$store->getCode(),
                'name' => (string)$store->getName(),
                'base_url' => (string)$store->getBaseUrl(),
                'secure_base_url' => (string)$store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB, true),
                'currency' => (string)$this->scopeConfig->getValue('currency/options/default', ScopeInterface::SCOPE_STORE, $storeId),
                'timezone' => (string)$this->scopeConfig->getValue('general/locale/timezone', ScopeInterface::SCOPE_STORE, $storeId),
                'is_active' => (bool)$store->getIsActive(),
            ];
        }

        return [
            'metrics' => [
                'store_count' => count($stores),
                'stores' => $stores,
            ],
        ];
    }
}
