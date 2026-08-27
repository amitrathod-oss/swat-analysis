<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Report;

use Mha\HealthCheck\Model\ScanResult;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;

class HtmlReportGenerator
{
    private const REPORT_DIRECTORY = 'health-reports';
    private const LATEST_REPORT = self::REPORT_DIRECTORY . '/latest.html';

    private WriteInterface $varDirectory;
    private ReportDataBuilder $reportDataBuilder;

    public function __construct(Filesystem $filesystem, ReportDataBuilder $reportDataBuilder)
    {
        $this->varDirectory = $filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $this->reportDataBuilder = $reportDataBuilder;
    }

    public function generate(ScanResult $scanResult): string
    {
        return $this->render($this->reportDataBuilder->build($scanResult));
    }

    public function write(ScanResult $scanResult): string
    {
        $this->varDirectory->create(self::REPORT_DIRECTORY);
        $this->varDirectory->writeFile(self::LATEST_REPORT, $this->generate($scanResult));

        return 'var/' . self::LATEST_REPORT;
    }

    public function writeTo(ScanResult $scanResult, string $path): string
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create report directory "%s".', $directory));
        }
        if (file_put_contents($path, $this->generate($scanResult)) === false) {
            throw new \RuntimeException(sprintf('Unable to write report "%s".', $path));
        }
        return $path;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function render(array $report): string
    {
        $application = is_array($report['application'] ?? null) ? $report['application'] : [];
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $metadata = is_array($report['scan_metadata'] ?? null) ? $report['scan_metadata'] : [];
        $profile = is_array($report['report_profile'] ?? null) ? $report['report_profile'] : [];
        $score = (int)($report['health_score'] ?? 0);
        $findings = is_array($report['findings'] ?? null) ? $report['findings'] : [];
        $customer = (string)($profile['customer_name'] ?? 'Magento project');

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $this->escape($customer) . ' — Magento Health Check Report</title><style>'
            . $this->styles() . '</style></head><body><footer>Mha HealthCheck <span>Internal health assessment</span></footer><main>'
            . $this->cover($customer, $profile, $report)
            . $this->contents($findings)
            . $this->dashboard($report, $application, $summary, $score)
            . '<section class="page-break"><h2>A. Report Overview</h2><p>This report shows the latest health results for your Magento store and highlights items that may need attention.</p>'
            . $this->keyValueTable([
                'Customer' => $customer,
                'Health Score' => $score . ' out of 100',
                'Report Type' => $profile['engagement_type'] ?? null,
                'Website' => $profile['merchant_domain'] ?? null,
                'Magento Version' => $application['version'] ?? null,
                'PHP Version' => $this->getCollectorMetric($report, 'magento', 'php_version'),
                'Scan Date' => $this->dateOnly((string)($report['completed_at'] ?? '')),
            ]) . '</section>'
            . '<section class="page-break"><h2>B. Executive Summary</h2><p>The scan completed with '
            . $this->escape((string)($summary['findings_total'] ?? 0)) . ' findings and '
            . $this->escape((string)($summary['scan_error_count'] ?? 0)) . ' scan errors.</p><div class="score">'
            . $score . '<span> / 100</span></div><p>The score reflects the number and importance of items found during this scan.</p>'
            . '</p><h3>C. Risk Level Guide</h3>' . $this->riskGuide($report['severity_counts'] ?? []) . '</section>'
            . '<section id="findings"><h2>D. Findings</h2>' . $this->renderDetailedFindings($findings) . '</section>'
            . '<section class="page-break"><h2>I. Domain Scorecard</h2>' . $this->renderDomainScorecard($report) . '</section>'
            . '<section class="page-break"><h2>E. Exceptions</h2>' . $this->renderExceptions($report) . '</section>'
            . '<section class="page-break"><h2>F. Patches</h2>' . $this->renderPatches($report) . '</section>'
            . '<section class="page-break"><h2>G. Store &amp; System Checks</h2>' . $this->renderCollectors($report)
            . $this->renderStoreInventory($report) . $this->renderExtensionInventory($report)
            . $this->renderExternalSources($report) . '</section>'
            . '<section><h2>H. Scan Details</h2>' . $this->definitionList([
                'Scan ID' => $report['scan_id'] ?? null,
                'Started at' => $report['started_at'] ?? null,
                'Completed at' => $report['completed_at'] ?? null,
                'Duration (seconds)' => $report['duration_seconds'] ?? null,
                'Report generated at' => $metadata['report_generated_at'] ?? null,
                'Checks included' => $summary['collector_statuses'] ?? null,
            ])
            . '</main></body></html>';
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $report
     */
    private function cover(string $customer, array $profile, array $report): string
    {
        return '<section class="cover page-break"><div class="brand">MHA<span>HEALTHCHECK</span></div><div class="cover-copy">'
            . '<p class="eyebrow">Magento Open Source</p><h1>Health Check<br>Report</h1><div class="cover-line"></div><p class="customer">'
            . $this->escape($customer) . '</p><p>' . $this->escape((string)($profile['engagement_type'] ?? ''))
            . '</p><p class="report-date">' . $this->escape($this->dateOnly((string)($report['completed_at'] ?? '')))
            . '</p></div><p class="cover-note">Independent, read-only technical assessment</p></section>';
    }

    /**
     * @param array<int, mixed> $findings
     */
    private function contents(array $findings): string
    {
        $html = '<section class="toc page-break"><p class="eyebrow">Report navigation</p><h2>Table of Contents</h2><ul>'
            . '<li>Dashboard</li><li>A. Report Overview</li><li>B. Executive Summary</li><li>C. Risk Level Guide</li>'
            . '<li>D. Findings (' . count($findings) . ')</li>'
            . '<li>E. Exceptions</li><li>F. Patches</li><li>G. Store &amp; System Checks</li>'
            . '<li>H. Scan Details</li><li>I. Domain Scorecard</li></ul></section>';

        return $html;
    }

    /**
     * Render a customer-friendly dashboard using this scan's measured data.
     *
     * @param array<string, mixed> $report
     * @param array<string, mixed> $application
     * @param array<string, mixed> $summary
     */
    private function dashboard(array $report, array $application, array $summary, int $score): string
    {
        $counts = is_array($report['severity_counts'] ?? null) ? $report['severity_counts'] : [];
        $findings = is_array($report['findings'] ?? null) ? $report['findings'] : [];
        $magento = is_array($report['collectors']['magento']['metrics'] ?? null) ? $report['collectors']['magento']['metrics'] : [];
        $database = is_array($report['collectors']['database']['metrics'] ?? null) ? $report['collectors']['database']['metrics'] : [];
        $redis = is_array($report['collectors']['redis']['metrics'] ?? null) ? $report['collectors']['redis']['metrics'] : [];
        $search = is_array($report['collectors']['opensearch']['metrics'] ?? null) ? $report['collectors']['opensearch']['metrics'] : [];
        $composer = is_array($report['collectors']['composer']['metrics'] ?? null) ? $report['collectors']['composer']['metrics'] : [];
        $logs = is_array($report['collectors']['logs']['metrics'] ?? null) ? $report['collectors']['logs']['metrics'] : [];
        $patches = is_array($report['collectors']['patches']['metrics'] ?? null) ? $report['collectors']['patches']['metrics'] : [];
        $cards = [
            ['label' => 'Health score', 'value' => $score . '/100', 'tone' => 'blue'],
            ['label' => 'Recommendations', 'value' => (string)count($findings), 'tone' => 'orange'],
            ['label' => 'Exceptions', 'value' => (string)($logs['exception_count'] ?? 0), 'tone' => 'red'],
            ['label' => 'Extensions', 'value' => (string)($magento['enabled_module_count'] ?? 0), 'tone' => 'purple'],
            ['label' => 'Scan alerts', 'value' => (string)($summary['scan_error_count'] ?? 0), 'tone' => 'teal'],
            ['label' => 'Security advisories', 'value' => (string)($composer['vulnerability_count'] ?? 0), 'tone' => 'yellow'],
            ['label' => 'Configured patches', 'value' => (string)($patches['patch_count'] ?? 0), 'tone' => 'green'],
        ];

        $html = '<section class="dashboard page-break"><div class="dashboard-header"><div><p class="eyebrow">Magento Open Source</p>'
            . '<h2>Health Dashboard</h2><p>Latest health results for ' . $this->escape((string)($report['completed_at'] ?? '')) . '</p></div>'
            . '<div class="dashboard-score"><span>Health score</span><strong>' . $score . '<small>/100</small></strong></div></div><div class="dashboard-cards">';
        foreach ($cards as $card) {
            $html .= '<article class="dashboard-card ' . $this->escape($card['tone']) . '"><span>' . $this->escape($card['label'])
                . '</span><strong>' . $this->escape($card['value']) . '</strong></article>';
        }
        $html .= '</div><div class="dashboard-columns"><div><h3>Recommendations by risk</h3>'
            . $this->riskGuide($counts) . '</div><div><h3>Application information</h3>'
            . $this->keyValueTable([
                'Magento version' => $application['version'] ?? null,
                'PHP version' => $magento['php_version'] ?? null,
                'Enabled modules' => $magento['enabled_module_count'] ?? null,
                'Database version' => $database['version'] ?? null,
                'Search version' => $search['version'] ?? null,
                'Redis version' => $redis['version'] ?? null,
            ]) . '</div></div><div class="dashboard-columns"><div><h3>Top recommendations</h3>'
            . $this->renderRecommendationSummary($findings) . '</div><div><h3>Storage and services</h3>'
            . $this->keyValueTable([
                'Largest tables' => is_array($database['tables'] ?? null) ? count($database['tables']) . ' measured' : null,
                'Buffer pool utilization' => $this->formatMetric($database['buffer_pool']['utilization_percent'] ?? null, '%'),
                'Redis memory utilization' => $this->formatMetric($redis['memory_utilization_percent'] ?? null, '%'),
                'Search cluster status' => $search['cluster_status'] ?? null,
                'Unassigned search shards' => $search['unassigned_shards'] ?? null,
                'Checks completed' => count($report['collectors'] ?? []),
            ]) . '</div></div></section>';

        return $html;
    }

    /**
     * @param array<int, mixed> $findings
     */
    private function renderRecommendationSummary(array $findings): string
    {
        if ($findings === []) {
            return '<p>No recommendations were generated.</p>';
        }
        $html = '<ul class="recommendation-list">';
        foreach (array_slice($findings, 0, 8) as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $html .= '<li><span class="risk-' . strtolower((string)($finding['risk_level'] ?? 'info')) . '">'
                . $this->escape((string)($finding['risk_level'] ?? 'Info')) . '</span> '
                . $this->escape((string)($finding['title'] ?? 'Finding')) . '</li>';
        }
        return $html . '</ul>';
    }

    /**
     * @param mixed $value
     */
    private function formatMetric($value, string $suffix): string
    {
        return $value === null || $value === '' ? 'N/A' : (string)$value . $suffix;
    }

    /**
     * @param mixed $counts
     */
    private function riskGuide($counts): string
    {
        $counts = is_array($counts) ? $counts : [];
        $levels = [
            'Severe' => 'Potential outage, major vulnerability, or severe availability/performance risk.',
            'High' => 'Significant security, configuration, performance, or service-component risk.',
            'Elevated' => 'Important performance, configuration, functionality, or availability issue.',
            'Medium' => 'Functional, operational, or user-experience issue.',
            'Low' => 'Non-critical operational notification.',
            'Info' => 'General environment or maintenance information.',
        ];
        $html = '<table><thead><tr><th>Risk Level</th><th>Meaning</th><th>Current Count</th></tr></thead><tbody>';
        foreach ($levels as $level => $meaning) {
            $html .= '<tr><td class="risk-' . strtolower($level) . '">' . $this->escape($level) . '</td><td>'
                . $this->escape($meaning) . '</td><td>' . $this->escape((string)($counts[strtolower($level)] ?? 0)) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * @param array<string, mixed> $values
     */
    private function keyValueTable(array $values): string
    {
        $html = '<table class="key-value"><thead><tr><th>Title</th><th>Description</th></tr></thead><tbody>';
        foreach ($values as $label => $value) {
            $html .= '<tr><td>' . $this->escape((string)$label) . '</td><td>' . $this->renderValue($value) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    private function dateOnly(string $value): string
    {
        try {
            return (new \DateTimeImmutable($value))->format('d F Y');
        } catch (\Throwable $exception) {
            return $value === '' ? 'N/A' : $value;
        }
    }

    /**
     * @param array<int, mixed> $findings
     */
    private function renderFindingsBySeverity(array $findings): string
    {
        $groups = ['severe' => [], 'high' => [], 'elevated' => [], 'medium' => [], 'low' => [], 'info' => []];
        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $severity = strtolower((string)($finding['risk_level'] ?? 'info'));
            $groups[$severity] = $groups[$severity] ?? [];
            $groups[$severity][] = $finding;
        }

        $html = '<div class="severity-grid">';
        foreach ($groups as $severity => $items) {
            $html .= '<article class="severity ' . $this->escape($severity) . '"><h3>' . $this->escape(ucfirst($severity))
                . '</h3><strong>' . count($items) . '</strong><ul>';
            foreach ($items as $finding) {
                $html .= '<li>' . $this->escape((string)($finding['rule_id'] ?? '')) . ' — '
                    . $this->escape((string)($finding['title'] ?? '')) . '</li>';
            }
            $html .= '</ul></article>';
        }

        return $html . '</div>';
    }

    /**
     * @param array<int, mixed> $findings
     */
    private function renderDetailedFindings(array $findings): string
    {
        if ($findings === []) {
            return '<p>No rule findings were generated.</p>';
        }

        $html = '';
        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $html .= '<article class="finding"><h3>' . $this->escape((string)($finding['rule_id'] ?? '')) . ': '
                . $this->escape((string)($finding['title'] ?? '')) . '</h3><h4>Overview</h4>'
                . $this->keyValueTable([
                    'Issue type' => $finding['issue_type'] ?? null,
                    'Risk level' => $finding['risk_level'] ?? null,
                    'Tool used' => $finding['tool_used'] ?? null,
                    'Data source' => $finding['data_source'] ?? null,
                'Last checked' => $finding['last_checked'] ?? null,
                    'Category' => $finding['category'] ?? null,
                    'Domain' => $finding['domain'] ?? null,
                    'Scoring penalty' => $finding['scoring_penalty'] ?? 0,
                ]) . '<h4>Finding Description</h4><p>' . $this->renderValue($finding['finding_description'] ?? null)
                . '</p><h4>Expected Results</h4><p>' . $this->renderValue($finding['expected_result'] ?? null)
                . '</p><h4>Observed Result</h4><p>' . $this->renderValue($finding['observed_result'] ?? null)
                . '</p><h4>Evidence</h4>' . $this->renderValue($finding['evidence'] ?? [])
                . '<h4>Possible Root Cause</h4><p>' . $this->renderValue($finding['root_cause'] ?? null)
                . '<h4>Site Impact</h4><p>' . $this->renderValue($finding['site_impact'] ?? null)
                . '</p><h4>Preconditions</h4>' . $this->renderValue($finding['preconditions'] ?? [])
                . '<h4>Recommendations</h4><p>' . $this->renderValue($finding['recommendation'] ?? null)
                . '</p><h4>References</h4>' . $this->renderValue($finding['references'] ?? [])
                . '</p></article>';
        }

        return $html;
    }

    /** @param array<string, mixed> $report */
    private function renderDomainScorecard(array $report): string
    {
        $domains = $report['health_score_details']['domain_scores'] ?? [];
        $history = $report['history'] ?? [];
        $html = '<p>This summary shows the health result for each area checked.</p>';
        if (!is_array($domains) || $domains === []) {
            $html .= '<p>No domain scores were calculated.</p>';
        } else {
            $html .= '<table><thead><tr><th>Domain</th><th>Score</th><th>Findings</th><th>Penalty</th></tr></thead><tbody>';
            foreach ($domains as $domain => $details) {
                if (!is_array($details)) {
                    continue;
                }
                $html .= '<tr><td>' . $this->escape((string)$domain) . '</td><td>'
                    . $this->escape((string)($details['score'] ?? 'N/A')) . '/100</td><td>'
                    . $this->escape((string)($details['findings'] ?? 0)) . '</td><td>'
                    . $this->escape((string)($details['deduction'] ?? 0)) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        return $html . '<h3>History comparison</h3>' . $this->renderValue($history);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function renderExceptions(array $report): string
    {
        $exceptions = $report['collectors']['logs']['metrics']['exceptions'] ?? [];
        if (!is_array($exceptions) || $exceptions === []) {
            return '<p>No grouped exceptions were found in the configured log window.</p>';
        }

        $html = '<div class="table-wrap"><table><thead><tr><th>Fingerprint</th><th>Type</th><th>Count</th><th>First seen</th><th>Last seen</th><th>Source</th></tr></thead><tbody>';
        foreach ($exceptions as $exception) {
            if (!is_array($exception)) {
                continue;
            }
            $html .= '<tr><td>' . $this->escape((string)($exception['fingerprint'] ?? '')) . '</td><td>'
                . $this->escape((string)($exception['exception_type'] ?? '')) . '</td><td>'
                . $this->escape((string)($exception['count'] ?? '')) . '</td><td>'
                . $this->escape((string)($exception['first_seen'] ?? '')) . '</td><td>'
                . $this->escape((string)($exception['last_seen'] ?? '')) . '</td><td>'
                . $this->escape((string)($exception['source'] ?? '')) . '</td></tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /**
     * @param array<string, mixed> $report
     */
    private function renderPatches(array $report): string
    {
        $patches = $report['collectors']['patches']['metrics']['patches'] ?? [];
        if (!is_array($patches) || $patches === []) {
            $qpt = $report['collectors']['patches']['metrics']['quality_patches_tool'] ?? [];
            $qptStatus = is_array($qpt) ? ($qpt['status'] ?? 'not_available') : 'not_available';
            return '<p>No installed fixes were found.</p><p><strong>Fix checker:</strong> '
                . $this->escape((string)$qptStatus)
                . '.</p>';
        }

        $qpt = $report['collectors']['patches']['metrics']['quality_patches_tool'] ?? [];
        $qptStatus = is_array($qpt) ? ($qpt['status'] ?? 'not_available') : 'not_available';
        $html = '<p>This section lists installed fixes and whether their application could be confirmed.</p>'
            . '<p><strong>Fix checker:</strong> ' . $this->escape((string)$qptStatus) . '</p>'
            . '<div class="table-wrap"><table><thead><tr><th>Patch ID</th><th>Description</th><th>Package</th><th>Category</th><th>Status</th><th>Recommended</th></tr></thead><tbody>';
        foreach ($patches as $patch) {
            if (!is_array($patch)) {
                continue;
            }
            $html .= '<tr><td>' . $this->escape((string)($patch['patch_id'] ?? '')) . '</td><td>'
                . $this->escape((string)($patch['description'] ?? '')) . '</td><td>'
                . $this->escape((string)($patch['package'] ?? '')) . '</td><td>'
                . $this->escape((string)($patch['category'] ?? '')) . '</td><td>'
                . $this->escape((string)($patch['status'] ?? '')) . '</td><td>'
                . $this->escape((string)($patch['recommended'] ?? '')) . '</td></tr>';
        }

        $html .= '</tbody></table></div><p><strong>Not applied:</strong> ' . $this->escape((string)($report['collectors']['patches']['metrics']['not_applied_count'] ?? 0))
            . ' &nbsp; <strong>Not verified:</strong> ' . $this->escape((string)($report['collectors']['patches']['metrics']['not_verified_count'] ?? 0))
            . ' &nbsp; <strong>Applied confirmed:</strong> ' . $this->escape((string)($report['collectors']['patches']['metrics']['applied_count'] ?? 'N/A'))
            . '</p><h3>Selected patch details</h3><div class="table-wrap"><table><thead><tr><th>Patch ID</th><th>Origin</th><th>Path</th><th>Application status</th><th>Details</th></tr></thead><tbody>';
        foreach ($patches as $patch) {
            if (!is_array($patch)) {
                continue;
            }
            $html .= '<tr><td>' . $this->escape((string)($patch['patch_id'] ?? '')) . '</td><td>'
                . $this->escape((string)($patch['origin'] ?? '')) . '</td><td>'
                . $this->escape((string)($patch['path'] ?? '')) . '</td><td>'
                . $this->escape((string)($patch['application_status'] ?? 'not_verified')) . '</td><td>'
                . $this->escape((string)($patch['details'] ?? '')) . '</td></tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /**
     * @param array<string, mixed> $report
     */
    private function renderCollectors(array $report): string
    {
        $collectors = is_array($report['collectors'] ?? null) ? $report['collectors'] : [];
        $html = '<div class="table-wrap"><table><thead><tr><th>Check</th><th>Status</th><th>Message</th><th>Measured Results</th></tr></thead><tbody>';
        foreach ($collectors as $code => $collector) {
            if (!is_array($collector)) {
                continue;
            }
            $html .= '<tr><td>' . $this->escape($this->collectorLabel((string)$code)) . '</td><td>'
                . $this->escape((string)($collector['status'] ?? '')) . '</td><td>'
                . $this->escape((string)($collector['message'] ?? '')) . '</td><td>'
                . $this->renderMetricList($this->summarizeMetrics($collector['metrics'] ?? [])) . '</td></tr>';
        }

        return $html . '</tbody></table></div>';
    }

    private function collectorLabel(string $code): string
    {
        $labels = [
            'database' => 'Database',
            'database_advanced' => 'Advanced Database Checks',
            'security_headers' => 'Security Headers',
            'opensearch' => 'OpenSearch',
            'fpc' => 'Full Page Cache',
            'php' => 'PHP',
            'http' => 'HTTP',
        ];

        return $labels[$code] ?? ucwords(str_replace('_', ' ', $code));
    }

    private function metricLabel(string $key): string
    {
        $labels = [
            'php_version' => 'PHP version',
            'enabled_module_count' => 'Installed modules',
            'custom_module_count' => 'Custom modules',
            'deployment_mode' => 'Store mode',
            'operating_system' => 'Operating system',
            'web_server' => 'Web server',
            'cache_types' => 'Cache types',
            'status_counts' => 'Job status totals',
            'stale_running_count' => 'Stale running jobs',
            'top_failing_job_codes' => 'Jobs with failures',
            'recent_errors' => 'Recent errors',
            'total_size_mb' => 'Total size (MB)',
            'tables' => 'Tables measured',
            'attribute_options' => 'Attribute options',
            'buffer_pool' => 'Database buffer pool',
            'trigger_count' => 'Database triggers',
            'triggers' => 'Triggers',
            'table_groups' => 'Table groups',
            'long_running_queries' => 'Long-running queries',
            'deadlocks' => 'Deadlocks',
            'row_lock_waits' => 'Row lock waits',
            'tested_urls' => 'URLs tested',
            'tested_page_types' => 'Page types tested',
            'hit_rate_percent' => 'Cache hit rate',
            'missing_headers' => 'Missing headers',
            'vulnerability_count' => 'Security advisories',
            'abandoned_package_count' => 'Abandoned packages',
        ];

        return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /** @param mixed $value */
    private function metricValue($value): string
    {
        if ($value === null || $value === '') {
            return 'Not available';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        return (string)$value;
    }

    /** @param mixed $value */
    private function nestedMetricValue($value): string
    {
        if (!is_array($value)) {
            return $this->metricValue($value);
        }
        if ($value === []) {
            return 'None';
        }

        $parts = [];
        foreach ($value as $key => $item) {
            if (is_scalar($item) || $item === null) {
                $parts[] = $this->metricLabel((string)$key) . ': ' . $this->metricValue($item);
            } else {
                $parts[] = $this->metricLabel((string)$key) . ': ' . count($item) . ' items';
            }
            if (count($parts) >= 5) {
                break;
            }
        }

        if ($parts === []) {
            return count($value) . ' items';
        }
        if (count($value) > count($parts)) {
            $parts[] = '+' . (count($value) - count($parts)) . ' more';
        }

        return implode('; ', $parts);
    }

    /** @param array<string, scalar> $metrics */
    private function renderMetricList(array $metrics): string
    {
        if ($metrics === []) {
            return 'No measured results available.';
        }

        $html = '<ul class="metric-list">';
        foreach ($metrics as $label => $value) {
            $html .= '<li><strong>' . $this->escape((string)$label) . ':</strong> '
                . $this->escape((string)$value) . '</li>';
        }

        return $html . '</ul>';
    }

    /** @param array<string, mixed> $report */
    private function renderStoreInventory(array $report): string
    {
        $stores = $report['collectors']['store']['metrics']['stores'] ?? [];
        if (!is_array($stores) || $stores === []) {
            return '<h3>Store Inventory</h3><p>No store information was collected.</p>';
        }

        $html = '<h3>Store Inventory</h3><div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Base URL</th><th>Secure URL</th><th>Currency</th><th>Timezone</th><th>Active</th></tr></thead><tbody>';
        foreach ($stores as $store) {
            if (!is_array($store)) {
                continue;
            }
            $html .= '<tr><td>' . $this->escape((string)($store['code'] ?? '')) . '</td><td>'
                . $this->escape((string)($store['name'] ?? '')) . '</td><td>'
                . $this->escape((string)($store['base_url'] ?? '')) . '</td><td>'
                . $this->escape((string)($store['secure_base_url'] ?? '')) . '</td><td>'
                . $this->escape((string)($store['currency'] ?? '')) . '</td><td>'
                . $this->escape((string)($store['timezone'] ?? '')) . '</td><td>'
                . $this->escape((string)($store['is_active'] ?? '')) . '</td></tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /** @param array<string, mixed> $report */
    private function renderExtensionInventory(array $report): string
    {
        $inventory = $report['collectors']['extensions']['metrics']['inventory'] ?? [];
        if (!is_array($inventory) || $inventory === []) {
            return '<h3>Extension Inventory</h3><p>No extension metadata was collected.</p>';
        }

        $html = '<h3>Extension Inventory (' . count($inventory) . ' records)</h3><p>Versions come from Magento module metadata or Composer runtime metadata. N/A means the source did not expose a version.</p><div class="table-wrap"><table><thead><tr><th>Name</th><th>Type</th><th>Composer Package</th><th>Version</th></tr></thead><tbody>';
        foreach ($inventory as $extension) {
            if (!is_array($extension)) {
                continue;
            }
            $html .= '<tr><td>' . $this->escape((string)($extension['name'] ?? '')) . '</td><td>'
                . $this->escape((string)($extension['type'] ?? '')) . '</td><td>'
                . $this->escape((string)($extension['package'] ?? '')) . '</td><td>'
                . $this->escape((string)($extension['version'] ?? 'N/A')) . '</td></tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /** @param array<string, mixed> $report */
    private function renderExternalSources(array $report): string
    {
        $sources = $report['collectors']['integrations']['metrics']['sources'] ?? [];
        if (!is_array($sources) || $sources === []) {
            return '<h3>External Data Sources</h3><p>No optional external integrations were configured.</p>';
        }

        $html = '<h3>External Data Sources</h3><p>Adobe SWAT cloud data, Security Scan results, UCT results, New Relic Managed Alerts, Fastly analytics, and Marketplace comparison data require their respective external services and credentials. This local report does not invent those values.</p><div class="table-wrap"><table><thead><tr><th>Source</th><th>Status</th><th>Data Collected</th><th>Reason</th></tr></thead><tbody>';
        foreach ($sources as $source => $details) {
            if (!is_array($details)) {
                continue;
            }
            $html .= '<tr><td>' . $this->escape((string)$source) . '</td><td>'
                . $this->escape((string)($details['status'] ?? '')) . '</td><td>'
                . $this->escape((string)($details['data_collected'] ?? '')) . '</td><td>'
                . $this->escape((string)($details['reason'] ?? '')) . '</td></tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /**
     * Keep the inventory readable in the printed report. Full rule evidence is rendered with each finding.
     *
     * @param mixed $metrics
     * @return array<string, string>
     */
    private function summarizeMetrics($metrics): array
    {
        if (!is_array($metrics)) {
            return [];
        }

        $summary = [];
        foreach ($metrics as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $summary[$this->metricLabel((string)$key)] = $this->metricValue($value);
                continue;
            }
            if (is_array($value)) {
                $summary[$this->metricLabel((string)$key)] = $this->nestedMetricValue($value);
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function definitionList(array $values): string
    {
        $html = '<dl>';
        foreach ($values as $label => $value) {
            $html .= '<dt>' . $this->escape((string)$label) . '</dt><dd>' . $this->renderValue($value) . '</dd>';
        }

        return $html . '</dl>';
    }

    /**
     * @param mixed $value
     */
    private function renderValue($value): string
    {
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return '<pre>' . $this->escape($encoded === false ? '' : $encoded) . '</pre>';
        }
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return $this->escape((string)$value);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    private function getCollectorMetric(array $report, string $collector, string $metric, $default = null)
    {
        $value = $report['collectors'][$collector]['metrics'][$metric] ?? $default;
        return $value;
    }

    private function section(string $title, string $content): string
    {
        return '<section><h2>' . $this->escape($title) . '</h2>' . $content . '</section>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function styles(): string
    {
        return '@page{size:letter;margin:.7in}body{margin:0;background:#ececec;color:#353535;font:15px/1.45 Arial,sans-serif}main{max-width:8.5in;margin:20px auto;background:#fff;box-shadow:0 1px 8px #aaa}section{padding:.7in;box-sizing:border-box}footer{position:fixed;bottom:0;left:0;right:0;padding:8px 7%;border-top:1px solid #ddd;color:#777;background:#fff;font:10px Georgia,serif;z-index:2}footer span{float:right}.page-break{break-after:page}.cover{position:relative;min-height:9.6in}.brand{position:absolute;right:.7in;top:.55in;color:#1d4e89;font-weight:bold;letter-spacing:.08em;font-size:13px}.brand span{display:block;color:#f28c28;text-align:right;font-size:9px}.cover-copy{padding-top:2.7in}.eyebrow{color:#f28c28;font-weight:bold;letter-spacing:.09em;text-transform:uppercase;font-size:12px}.cover h1{font:54px/1.02 Georgia,serif;margin:0;color:#252525}.cover-line{width:100px;border-top:4px solid #f28c28;margin:30px 0}.customer{font:24px Georgia,serif;margin-bottom:4px}.report-date{margin-top:42px;color:#666}.cover-note{position:absolute;bottom:.8in;color:#777;font-style:italic}.toc{min-height:9.6in}.toc h2,h2{font:28px Georgia,serif;color:#f28c28;margin:0 0 22px}.toc ul{list-style:none;padding:0;margin:0;line-height:2.05;font-size:16px}.toc .sub-item{margin-left:28px;font-size:14px;color:#555}h3{font:22px Georgia,serif;margin:28px 0 14px;color:#333}h4{font-size:15px;margin:22px 0 6px;color:#444}p{margin:0 0 14px}.score{font:48px Georgia,serif;color:#1d4e89;margin:14px 0}.score span{font-size:20px;color:#666}table{border-collapse:collapse;width:100%;margin:10px 0 18px;break-inside:avoid}th,td{border:1px solid #999;padding:9px;vertical-align:top;text-align:left}th{background:#f5f4f9;text-transform:uppercase;font-size:12px}td:first-child{font-weight:bold}.key-value td:first-child{width:42%}.risk-severe{color:#aa1f1f}.risk-high{color:#bd5a00}.risk-elevated{color:#896b00}.finding,.exception{border-top:2px solid #ddd;padding:18px 0 26px;break-inside:avoid}.finding:first-of-type{border-top:0;padding-top:0}.finding h3{color:#1d4e89}.finding h4{color:#f28c28}.table-wrap{overflow:auto}.metric-list{margin:0;padding-left:18px}.metric-list li{margin:2px 0}.metric-list strong{font-weight:bold}.dashboard-header{display:flex;justify-content:space-between;gap:24px;border-bottom:1px solid #ddd;padding-bottom:16px}.dashboard-header h2{margin-bottom:5px}.dashboard-score{background:#f5f8fb;border-left:5px solid #1d4e89;padding:12px 18px;min-width:120px}.dashboard-score span{display:block;font-size:11px;text-transform:uppercase;color:#666}.dashboard-score strong{display:block;font:34px Georgia,serif;color:#1d4e89}.dashboard-score small{font:16px Arial;color:#666}.dashboard-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:20px 0}.dashboard-card{border-top:4px solid #1d4e89;background:#f7f7f7;padding:14px}.dashboard-card span{display:block;color:#666;font-size:12px}.dashboard-card strong{display:block;font:28px Georgia,serif;margin-top:8px}.dashboard-card.orange{border-color:#f28c28}.dashboard-card.red{border-color:#c94c4c}.dashboard-card.purple{border-color:#625ed4}.dashboard-card.teal{border-color:#3db5b5}.dashboard-card.yellow{border-color:#e0b400}.dashboard-columns{display:grid;grid-template-columns:1fr 1fr;gap:24px}.dashboard-columns>div{min-width:0}.recommendation-list{padding-left:20px}.recommendation-list li{margin:8px 0}.recommendation-list span{font-weight:bold;margin-right:4px}dl{display:grid;grid-template-columns:minmax(160px,260px) 1fr;gap:8px 16px;margin:0}dt{font-weight:bold}dd{margin:0;min-width:0}pre{white-space:pre-wrap;word-break:break-word;margin:0;font:11px/1.35 monospace;max-width:100%}@media print{body{background:#fff}main{max-width:none;margin:0;box-shadow:none}}@media screen and (max-width:720px){main{margin:0;box-shadow:none}section{padding:28px 18px}.cover{min-height:680px}.cover-copy{padding-top:180px}.cover h1{font-size:42px}.key-value td:first-child{width:35%}.dashboard-columns{grid-template-columns:1fr}.dashboard-cards{grid-template-columns:repeat(2,1fr)}}';
    }
}
