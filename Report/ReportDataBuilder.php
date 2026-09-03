<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Report;

use Mha\HealthCheck\Model\ScanResult;
use Mha\HealthCheck\Config\HealthCheckConfig;
use Mha\HealthCheck\Security\SecretSanitizer;

class ReportDataBuilder
{
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
        $actionPlan = $this->buildDeveloperActionPlan($report['findings']);
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
        $report['health_status'] = $actionPlan['status'];
        $report['developer_action_plan'] = $actionPlan;
        $report['metadata'] = [
            'schema_version' => '1.2',
            'scan_id' => $report['scan_id'] ?? null,
            'generated_at' => $report['completed_at'] ?? null,
            'read_only' => true,
            'analyzer' => 'Mha HealthCheck',
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
            'health_status' => $actionPlan['status'],
            'health_status_message' => $actionPlan['message'],
            'developer_action_plan' => $actionPlan,
            'scan_scope' => $this->scanScope($scanResult),
        ];
        $report['scan_metadata'] = [
            'analyzer' => 'Mha HealthCheck',
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

    /**
     * Turn rule findings into a developer-facing work queue. The score remains a
     * trend indicator; this plan states which work should happen first.
     *
     * @param array<int, array<string, mixed>> $findings
     * @return array<string, mixed>
     */
    private function buildDeveloperActionPlan(array $findings): array
    {
        $buckets = [
            'fix_now' => ['label' => 'Fix now', 'description' => 'Resolve before the next release or as an incident response.', 'items' => []],
            'plan_next' => ['label' => 'Plan next', 'description' => 'Add to the next development or operations sprint.', 'items' => []],
            'backlog' => ['label' => 'Backlog', 'description' => 'Track as a minor improvement or maintenance task.', 'items' => []],
        ];
        $severityOrder = ['severe' => 0, 'high' => 1, 'elevated' => 2, 'medium' => 3, 'low' => 4, 'info' => 5];

        foreach ($findings as $finding) {
            $severity = strtolower((string)($finding['risk_level'] ?? 'info'));
            $bucket = in_array($severity, ['severe', 'high'], true)
                ? 'fix_now'
                : (in_array($severity, ['elevated', 'medium'], true) ? 'plan_next' : 'backlog');
            $domain = (string)($finding['domain'] ?? 'Application');
            $buckets[$bucket]['items'][] = [
                'priority' => $buckets[$bucket]['label'],
                'owner' => $this->recommendedOwner($domain),
                'finding' => $finding,
                'severity_order' => $severityOrder[$severity] ?? 99,
            ];
        }

        foreach ($buckets as &$bucket) {
            usort($bucket['items'], static function (array $left, array $right): int {
                return $left['severity_order'] <=> $right['severity_order'];
            });
            foreach ($bucket['items'] as &$item) {
                unset($item['severity_order']);
            }
            unset($item);
            $bucket['count'] = count($bucket['items']);
        }
        unset($bucket);

        $fixNowCount = $buckets['fix_now']['count'];
        $planNextCount = $buckets['plan_next']['count'];
        if ($fixNowCount > 0) {
            $status = 'Action required';
            $message = sprintf('%d urgent issue(s) need developer attention before the next release.', $fixNowCount);
        } elseif ($planNextCount > 0) {
            $status = 'Planned work needed';
            $message = sprintf('%d issue(s) should be scheduled in the next sprint.', $planNextCount);
        } elseif ($buckets['backlog']['count'] > 0) {
            $status = 'Healthy with minor improvements';
            $message = 'No urgent issues were found; track the remaining maintenance items in the backlog.';
        } else {
            $status = 'Healthy';
            $message = 'No actionable rule findings were generated by this scan.';
        }

        return [
            'status' => $status,
            'message' => $message,
            'buckets' => $buckets,
        ];
    }

    private function recommendedOwner(string $domain): string
    {
        return match ($domain) {
            'Infrastructure', 'Availability', 'Performance' => 'Platform / DevOps',
            'Database' => 'Magento developer / DBA',
            'Security' => 'Magento developer / Security',
            default => 'Magento developer',
        };
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
