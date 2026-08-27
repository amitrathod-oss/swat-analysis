<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Collector;

use Mha\HealthCheck\Config\HealthCheckConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

class CronCollector implements CollectorInterface
{
    private ResourceConnection $resourceConnection;
    private HealthCheckConfig $config;

    public function __construct(ResourceConnection $resourceConnection, HealthCheckConfig $config)
    {
        $this->resourceConnection = $resourceConnection;
        $this->config = $config;
    }

    public function getCode(): string
    {
        return 'cron';
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
        $connection = $this->resourceConnection->getConnection();
        $scheduleTable = $connection->quoteIdentifier($this->resourceConnection->getTableName('cron_schedule'));
        $windowHours = $this->config->getPositiveInt('scan.cron_window_hours', 24);
        $staleMinutes = $this->config->getPositiveInt('scan.cron_stale_running_minutes', 60);
        $sampleLimit = $this->config->getPositiveInt('scan.cron_error_sample_limit', 20);

        return [
            'metrics' => [
                'window_hours' => $windowHours,
                'status_counts' => $this->collectStatusCounts($connection, $scheduleTable, $windowHours),
                'stale_running_count' => (int)$connection->fetchOne(
                    'SELECT COUNT(*) FROM ' . $scheduleTable
                    . ' WHERE status = "running" AND executed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL '
                    . $staleMinutes . ' MINUTE)'
                ),
                'top_failing_job_codes' => $this->collectTopFailingJobs($connection, $scheduleTable, $windowHours, $sampleLimit),
                'recent_errors' => $this->collectRecentErrors($connection, $scheduleTable, $windowHours, $sampleLimit),
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function collectStatusCounts(AdapterInterface $connection, string $scheduleTable, int $windowHours): array
    {
        $counts = [
            'error' => 0,
            'missed' => 0,
            'pending' => 0,
            'running' => 0,
            'success' => 0,
        ];
        $results = $connection->fetchPairs(
            'SELECT status, COUNT(*) FROM ' . $scheduleTable
            . ' WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $windowHours . ' HOUR) '
            . 'GROUP BY status'
        );
        foreach ($results as $status => $count) {
            $counts[(string)$status] = (int)$count;
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function collectTopFailingJobs(
        AdapterInterface $connection,
        string $scheduleTable,
        int $windowHours,
        int $sampleLimit
    ): array {
        $rows = $connection->fetchPairs(
            'SELECT job_code, COUNT(*) FROM ' . $scheduleTable
            . ' WHERE status = "error" AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $windowHours . ' HOUR) '
            . 'GROUP BY job_code ORDER BY COUNT(*) DESC LIMIT ' . $sampleLimit
        );

        return array_map('intval', $rows);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function collectRecentErrors(
        AdapterInterface $connection,
        string $scheduleTable,
        int $windowHours,
        int $sampleLimit
    ): array {
        $rows = $connection->fetchAll(
            'SELECT job_code, LEFT(messages, 1000) AS message, executed_at '
            . 'FROM ' . $scheduleTable
            . ' WHERE status = "error" AND messages IS NOT NULL '
            . 'AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $windowHours . ' HOUR) '
            . 'ORDER BY executed_at DESC LIMIT ' . $sampleLimit
        );

        return array_map(static function (array $row): array {
            return [
                'job_code' => (string)$row['job_code'],
                'message' => (string)$row['message'],
                'executed_at' => (string)$row['executed_at'],
            ];
        }, $rows);
    }
}
