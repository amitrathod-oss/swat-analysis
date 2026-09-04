<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Collector;

use Mha\HealthCheck\Config\HealthCheckConfig;
use Mha\HealthCheck\Service\RedisClientFactory;
use Magento\Framework\App\DeploymentConfig;

class RedisCollector implements CollectorInterface
{
    private DeploymentConfig $deploymentConfig;
    private RedisClientFactory $redisClientFactory;
    private HealthCheckConfig $config;

    public function __construct(
        DeploymentConfig $deploymentConfig,
        RedisClientFactory $redisClientFactory,
        HealthCheckConfig $config
    ) {
        $this->deploymentConfig = $deploymentConfig;
        $this->redisClientFactory = $redisClientFactory;
        $this->config = $config;
    }

    public function getCode(): string
    {
        return 'redis';
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
        $configurations = $this->getConfigurations();
        $connectionConfiguration = $this->firstConnectionConfiguration($configurations);
        if ($connectionConfiguration === null) {
            return [
                'status' => 'not_applicable',
                'message' => 'Redis or Valkey is not configured for Magento cache or sessions.',
                'metrics' => [],
            ];
        }

        try {
            $client = $this->redisClientFactory->create($connectionConfiguration);
            $client->setMaxConnectRetries(1);
            $client->connect();
            $ping = $client->ping();
            if ($ping !== true && strtoupper((string)$ping) !== 'PONG') {
                throw new \RuntimeException('Redis or Valkey did not acknowledge the read-only PING command.');
            }
            $server = (array)$client->info('server');
            $memory = (array)$client->info('memory');
            $stats = (array)$client->info('stats');
            $clients = (array)$client->info('clients');
            $keyspace = (array)$client->info('keyspace');
            $usedMemory = (int)($memory['used_memory'] ?? 0);
            $maxMemory = (int)($memory['maxmemory'] ?? 0);

            return [
                'metrics' => [
                    'ping' => true,
                    'version' => (string)($server['redis_version'] ?? $server['valkey_version'] ?? 'unknown'),
                    'used_memory_bytes' => $usedMemory,
                    'max_memory_bytes' => $maxMemory,
                    'memory_utilization_percent' => $maxMemory > 0 ? round(($usedMemory / $maxMemory) * 100, 2) : null,
                    'evicted_keys' => (int)($stats['evicted_keys'] ?? 0),
                    'connected_clients' => (int)($clients['connected_clients'] ?? 0),
                    'keyspace' => $keyspace,
                    'configured_backends' => array_keys($configurations),
                ],
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'unavailable',
                'message' => 'Redis or Valkey could not be queried.',
                'metrics' => [],
            ];
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getConfigurations(): array
    {
        $configurations = [
            'session' => $this->deploymentConfig->get('session/redis', []),
            'default_cache' => $this->deploymentConfig->get('cache/frontend/default/backend_options', []),
            'page_cache' => $this->deploymentConfig->get('cache/frontend/page_cache/backend_options', []),
        ];

        return array_filter($configurations, 'is_array');
    }

    /**
     * @param array<string, array<string, mixed>> $configurations
     * @return array<string, mixed>|null
     */
    private function firstConnectionConfiguration(array $configurations): ?array
    {
        foreach ($configurations as $configuration) {
            if (!empty($configuration['server'])) {
                $configuration['host'] = $configuration['server'];
            }
            if (!empty($configuration['host'])) {
                return $configuration;
            }
        }

        return null;
    }
}
