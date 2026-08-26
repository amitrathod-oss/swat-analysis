<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Collector;

use Sigma\HealthCheck\Config\HealthCheckConfig;
use Magento\Framework\Indexer\ConfigInterface;
use Magento\Framework\Indexer\IndexerRegistry;

class IndexerCollector implements CollectorInterface
{
    private ConfigInterface $indexerConfig;
    private IndexerRegistry $indexerRegistry;
    private HealthCheckConfig $config;

    public function __construct(
        ConfigInterface $indexerConfig,
        IndexerRegistry $indexerRegistry,
        HealthCheckConfig $config
    ) {
        $this->indexerConfig = $indexerConfig;
        $this->indexerRegistry = $indexerRegistry;
        $this->config = $config;
    }

    public function getCode(): string
    {
        return 'indexer';
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
        $indexers = [];
        $unexpectedRealtime = [];
        $expectedSchedule = array_flip($this->config->getStringList('indexers.expected_schedule'));

        foreach ($this->indexerConfig->getIndexers() as $indexerId => $definition) {
            try {
                $indexer = $this->indexerRegistry->get((string)$indexerId);
                $isScheduled = $indexer->isScheduled();
                $indexers[(string)$indexerId] = [
                    'title' => $indexer->getTitle(),
                    'status' => $indexer->getStatus(),
                    'mode' => $isScheduled ? 'schedule' : 'realtime',
                    'last_updated' => $indexer->getLatestUpdated(),
                ];
                if (isset($expectedSchedule[$indexerId]) && !$isScheduled) {
                    $unexpectedRealtime[] = (string)$indexerId;
                }
            } catch (\Throwable $exception) {
                $indexers[(string)$indexerId] = [
                    'status' => 'unavailable',
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return [
            'metrics' => [
                'indexers' => $indexers,
                'unexpected_realtime_count' => count($unexpectedRealtime),
                'unexpected_realtime' => $unexpectedRealtime,
            ],
        ];
    }
}
