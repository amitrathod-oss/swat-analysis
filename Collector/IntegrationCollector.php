<?php
declare(strict_types=1);

namespace Asiamarket\HealthCheck\Collector;

use Asiamarket\HealthCheck\Config\HealthCheckConfig;

/**
 * Makes optional external data sources explicit. It never invents external
 * findings and never sends credentials or data without an adapter being
 * configured by the operator.
 */
class IntegrationCollector implements CollectorInterface
{
    private HealthCheckConfig $config;

    public function __construct(HealthCheckConfig $config)
    {
        $this->config = $config;
    }

    public function getCode(): string
    {
        return 'integrations';
    }

    public function isSupported(array $context = []): bool
    {
        return true;
    }

    public function collect(array $context = []): array
    {
        $sources = [
            'newrelic',
            'datadog',
            'lighthouse',
            'fastly',
            'adobe_security_scan',
            'uct',
            'adobe_swat_cloud',
            'marketplace_metadata',
            'support_tickets',
        ];
        $status = [];
        foreach ($sources as $source) {
            $configured = $this->config->get('integrations.' . $source . '.enabled', false);
            $status[$source] = [
                'status' => $configured === true ? 'configured_adapter_required' : 'not_configured',
                'data_collected' => false,
                'reason' => 'Optional external integration is not queried by the local read-only analyzer.',
            ];
        }
        return ['metrics' => [
            'sources' => $status,
            'external_data_available' => false,
        ]];
    }
}
