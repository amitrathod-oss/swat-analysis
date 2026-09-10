<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Collector;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/** Collects safe Magento configuration evidence for HP-041..HP-080. */
class ConfigurationCollector implements CollectorInterface
{
    public function __construct(
        private ScopeConfigInterface $scope,
        private DeploymentConfig $deployment,
        private ResourceConnection $resource,
        private StoreManagerInterface $stores
    ) {}

    public function getCode(): string { return 'configuration'; }
    public function isSupported(array $context = []): bool { return true; }

    public function collect(array $context = []): array
    {
        $paths = [
            'web/unsecure/base_url', 'web/secure/base_url', 'web/cookie/cookie_domain', 'web/cookie/cookie_path',
            'web/cookie/cookie_lifetime', 'web/cookie/cookie_httponly', 'web/cookie/cookie_secure',
            'web/seo/use_rewrites', 'system/full_page_cache/caching_application', 'system/full_page_cache/varnish/host',
            'system/full_page_cache/varnish/port', 'session/save', 'web/session/use_remote_addr', 'web/session/use_http_via',
            'catalog/search/engine', 'catalog/search/min_query_length', 'general/country/default', 'general/country/allow',
            'general/locale/code', 'general/locale/timezone', 'currency/options/base', 'currency/options/allow',
            'tax/defaults/country', 'tax/calculation/algorithm', 'checkout/options/guest_checkout',
            'customer/password/reset_link_expiration_period', 'sales_email/general/async_sending',
            'catalog/productalert/allow_price', 'catalog/productalert/allow_stock', 'design/search_engine_robots/default_robots',
            'cms/wysiwyg/enabled', 'dev/image/adapter',
            'smtp/general/enabled', 'trans_email/ident_general/email', 'carriers/flatrate/active',
            'payment/checkmo/active', 'payment/braintree/active', 'catalog/productalert/allow_price',
            'catalog/productalert/allow_stock', 'captcha/frontend/area', 'recaptcha_frontend/type_for',
            'cataloginventory/options/manage_stock', 'cataloginventory/options/backorders', 'graphql/endpoint',
        ];
        $config = [];
        foreach ($paths as $path) {
            $value = $this->scope->getValue($path);
            if ($value !== null && $value !== '') $config[$path] = is_scalar($value) ? (string)$value : $value;
        }
        $stores = [];
        foreach ($this->stores->getStores(false) as $store) {
            $stores[] = ['id' => (int)$store->getId(), 'code' => (string)$store->getCode(), 'website_id' => (int)$store->getWebsiteId(), 'group_id' => (int)$store->getGroupId(), 'active' => (bool)$store->getIsActive(), 'base_url' => (string)$store->getBaseUrl(), 'secure_base_url' => (string)$store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB, true)];
        }
        $db = $this->resource->getConnection();
        $taxClasses = [];
        $configRows = [];
        try { $taxClasses = $db->fetchAll('SELECT class_id, class_name, class_type FROM ' . $db->quoteIdentifier($this->resource->getTableName('tax_class'))); } catch (\Throwable $e) { /* evidence unavailable */ }
        try { $configRows = $db->fetchAll('SELECT scope, scope_id, path, value FROM ' . $db->quoteIdentifier($this->resource->getTableName('core_config_data')) . ' WHERE path LIKE "carriers/%" OR path LIKE "payment/%" OR path LIKE "%captcha%" OR path LIKE "%recaptcha%" OR path LIKE "cataloginventory/%" OR path LIKE "graphql/%" OR path LIKE "%smtp%" OR path LIKE "trans_email/%"'); } catch (\Throwable $e) { /* evidence unavailable */ }
        return ['metrics' => ['config' => $config, 'config_rows' => $configRows, 'stores' => $stores, 'tax_classes' => $taxClasses, 'deployment' => $this->deployment->get('session') ?? [], 'db_connection' => ['host' => (string)$this->deployment->get('db/connection/default/host', '')]]];
    }
}
