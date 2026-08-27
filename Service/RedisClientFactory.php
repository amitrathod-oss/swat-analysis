<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Service;

class RedisClientFactory
{
    /**
     * @param array<string, mixed> $configuration
     */
    public function create(array $configuration): \Credis_Client
    {
        return new \Credis_Client(
            (string)($configuration['host'] ?? '127.0.0.1'),
            isset($configuration['port']) ? (int)$configuration['port'] : 6379,
            isset($configuration['timeout']) ? (float)$configuration['timeout'] : 2.5,
            '',
            isset($configuration['database']) ? (int)$configuration['database'] : 0,
            isset($configuration['password']) ? (string)$configuration['password'] : null,
            isset($configuration['username']) ? (string)$configuration['username'] : null
        );
    }
}
