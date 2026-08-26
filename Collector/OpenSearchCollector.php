<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Collector;

use Sigma\HealthCheck\Config\HealthCheckConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;

class OpenSearchCollector implements CollectorInterface
{
    private ScopeConfigInterface $scopeConfig;
    private CurlFactory $curlFactory;
    private HealthCheckConfig $config;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        CurlFactory $curlFactory,
        HealthCheckConfig $config
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->curlFactory = $curlFactory;
        $this->config = $config;
    }

    public function getCode(): string
    {
        return 'opensearch';
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
        $connection = $this->getConnectionConfiguration();
        if ($connection === null) {
            return [
                'status' => 'not_applicable',
                'message' => 'OpenSearch or Elasticsearch is not configured.',
                'metrics' => [],
            ];
        }

        try {
            $root = $this->requestJson($connection, '/');
            $health = $this->requestJson($connection, '/_cluster/health');
            $indices = $this->requestJson(
                $connection,
                '/_cat/indices?format=json&h=health,status,index,pri,rep,docs.count,store.size&bytes=b'
            );
            $allocation = $this->requestJson(
                $connection,
                '/_cat/allocation?format=json&h=node,shards,disk.indices,disk.used,disk.avail,disk.percent&bytes=b'
            );

            return [
                'metrics' => [
                    'version' => (string)($root['version']['number'] ?? 'unknown'),
                    'cluster_status' => (string)($health['status'] ?? 'unknown'),
                    'node_count' => (int)($health['number_of_nodes'] ?? 0),
                    'active_shards' => (int)($health['active_shards'] ?? 0),
                    'unassigned_shards' => (int)($health['unassigned_shards'] ?? 0),
                    'indices' => array_slice(is_array($indices) ? $indices : [], 0, $this->config->getPositiveInt('scan.search_max_indices', 100)),
                    'disk_allocation' => array_slice(is_array($allocation) ? $allocation : [], 0, $this->config->getPositiveInt('scan.search_max_indices', 100)),
                ],
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'unavailable',
                'message' => 'OpenSearch or Elasticsearch could not be queried.',
                'metrics' => [],
            ];
        }
    }

    /**
     * @return array<string, string>|null
     */
    private function getConnectionConfiguration(): ?array
    {
        $engine = (string)$this->scopeConfig->getValue('catalog/search/engine');
        $prefixes = $engine === 'opensearch'
            ? ['catalog/search/opensearch_', 'catalog/search/elasticsearch7_']
            : ['catalog/search/elasticsearch7_', 'catalog/search/opensearch_'];

        foreach ($prefixes as $prefix) {
            $host = (string)$this->scopeConfig->getValue($prefix . 'server_hostname');
            if ($host === '') {
                continue;
            }
            return [
                'host' => $host,
                'port' => (string)($this->scopeConfig->getValue($prefix . 'server_port') ?: '9200'),
                'username' => (string)$this->scopeConfig->getValue($prefix . 'username'),
                'password' => (string)$this->scopeConfig->getValue($prefix . 'password'),
            ];
        }

        return null;
    }

    /**
     * @param array<string, string> $connection
     * @return array<string, mixed>|array<int, mixed>
     */
    private function requestJson(array $connection, string $path): array
    {
        /** @var Curl $client */
        $client = $this->curlFactory->create();
        $client->setTimeout($this->config->getPositiveInt('scan.http_timeout_seconds', 10));
        if ($connection['username'] !== '' || $connection['password'] !== '') {
            $client->setCredentials($connection['username'], $connection['password']);
        }
        $scheme = (string)$this->config->get('opensearch.scheme', 'http');
        if (!in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'http';
        }
        $client->get($scheme . '://' . $connection['host'] . ':' . $connection['port'] . $path);
        if ($client->getStatus() !== 200) {
            throw new \RuntimeException('Search service returned an unexpected HTTP status.');
        }
        $result = json_decode($client->getBody(), true);
        if (!is_array($result)) {
            throw new \RuntimeException('Search service returned an invalid JSON response.');
        }

        return $result;
    }
}
