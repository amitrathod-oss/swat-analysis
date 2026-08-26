<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Report;

use Sigma\HealthCheck\Model\ScanResult;
use Sigma\HealthCheck\Config\HealthCheckConfig;
use Sigma\HealthCheck\Security\SecretSanitizer;

class ReportDataBuilder
{
    private const SCORE_DISCLAIMER = 'This is a custom Magento Open Source health score and is not Adobe\'s SWAT Health Index.';

    private HealthScoreCalculator $healthScoreCalculator;
    private SecretSanitizer $secretSanitizer;
    private HealthCheckConfig $config;
    private ?HistoryManager $historyManager;

    public function __construct(
        HealthScoreCalculator $healthScoreCalculator,
        SecretSanitizer $secretSanitizer,
        HealthCheckConfig $config,
        ?HistoryManager $historyManager = null
    ) {
        $this->healthScoreCalculator = $healthScoreCalculator;
        $this->secretSanitizer = $secretSanitizer;
        $this->config = $config;
        $this->historyManager = $historyManager;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(ScanResult $scanResult): array
    {
        $report = $scanResult->toArray();
        $scoreDetails = $this->healthScoreCalculator->calculate($report['findings']);
        $magentoMetrics = $report['collectors']['magento']['metrics'] ?? [];
        $collectorStatuses = [
            'success' => 0,
            'unavailable' => 0,
            'not_applicable' => 0,
        ];

        foreach ($report['collectors'] as $collector) {
            $status = (string)($collector['status'] ?? 'unavailable');
            if (!array_key_exists($status, $collectorStatuses)) {
                $collectorStatuses[$status] = 0;
            }
            $collectorStatuses[$status]++;
        }

        $report['application'] = [
            'platform' => 'Magento Open Source',
            'version' => is_array($magentoMetrics) ? ($magentoMetrics['version'] ?? null) : null,
            'edition' => is_array($magentoMetrics) ? ($magentoMetrics['edition'] ?? null) : null,
        ];
        $report['health_score'] = $scoreDetails['score'];
        $report['health_score_details'] = $scoreDetails;
        $report['metadata'] = [
            'schema_version' => '1.1',
            'scan_id' => $report['scan_id'] ?? null,
            'generated_at' => $report['completed_at'] ?? null,
            'read_only' => true,
            'analyzer' => 'Sigma HealthCheck',
            'scan_scope' => $this->scanScope($scanResult),
        ];
        $context = $scanResult->getContext();
        $baseUrl = $this->config->resolveBaseUrl($context);
        if ($baseUrl === '') {
            $baseUrl = $this->getReportString('merchant_domain', 'Not configured');
        }
        $report['environment'] = [
            'application' => $report['application'],
            'base_url' => $baseUrl,
            'magento_root' => $context['magento_root'] ?? $this->getReportString('magento_root', 'Magento root'),
        ];
        $report['runtime'] = [
            'duration_seconds' => $report['duration_seconds'] ?? null,
            'collector_count' => count($report['collectors']),
            'scan_errors' => count($report['scan_errors']),
        ];
        $report['summary'] = [
            'findings_total' => count($report['findings']),
            'collector_statuses' => $collectorStatuses,
            'scan_error_count' => count($report['scan_errors']),
            'severity_counts' => $report['severity_counts'],
            'priority_counts' => $scoreDetails['priority_counts'],
            'top_recommendations' => array_slice($report['findings'], 0, 10),
            'scan_scope' => $this->scanScope($scanResult),
        ];
        $report['scan_metadata'] = [
            'analyzer' => 'Sigma HealthCheck',
            'score_disclaimer' => self::SCORE_DISCLAIMER,
            'score_algorithm' => 'Deduplicate identical evidence and cap the total penalty contributed by each rule; informational findings have no penalty.',
            'report_generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
        $report['history'] = $this->historyManager ? $this->historyManager->comparison($report) : [
            'status' => 'history_unavailable',
        ];
        $customerName = $this->getReportString('customer_name', '');
        $merchantDomain = $this->getReportString('merchant_domain', '');
        $report['report_profile'] = [
            'customer_name' => $customerName !== '' ? $customerName : $this->config->getActiveStoreName(),
            'engagement_type' => $this->getReportString('engagement_type', 'Magento Open Source Health Check Report'),
            'merchant_domain' => $merchantDomain !== '' ? $merchantDomain : ($baseUrl !== '' ? $baseUrl : 'Not configured'),
            'project_team' => $this->getReportString('project_team', 'Magento Operations'),
            'timezone' => $this->getReportString('timezone', 'UTC'),
        ];

        return $this->secretSanitizer->sanitize($report);
    }

    private function getReportString(string $key, string $default): string
    {
        $value = $this->config->get('report.' . $key, $default);
        return is_scalar($value) && (string)$value !== '' ? (string)$value : $default;
    }

    /** @return array<string, mixed> */
    private function scanScope(ScanResult $scanResult): array
    {
        $context = $scanResult->getContext();
        $only = is_array($context['only'] ?? null) ? array_values($context['only']) : [];
        $skip = is_array($context['skip'] ?? null) ? array_values($context['skip']) : [];
        return [
            'type' => $only !== [] || $skip !== [] ? 'partial' : 'full',
            'only' => $only,
            'skip' => $skip,
            'complete_dashboard_data' => $only === [] && $skip === [],
        ];
    }
}
