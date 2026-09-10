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
            . '<section id="findings"><h2>A. Findings (' . count($findings) . ' failed findings)</h2>' . $this->renderDetailedFindings($findings) . '</section>'
            . '<section class="page-break"><h2>B. Domain Scorecard</h2>' . $this->renderDomainScorecard($report) . '</section>'
            . '<section class="page-break"><h2>C. Exceptions</h2>' . $this->renderExceptions($report) . '</section>'
            . '<section class="page-break"><h2>D. Patches</h2>' . $this->renderPatches($report) . '</section>'
            . '<section class="page-break"><h2>E. Store &amp; System Checks</h2>' . $this->renderCollectors($report)
            . $this->renderRuleChecks($report) . $this->renderStoreInventory($report)
            . '</section>'
            . '<section><h2>F. Scan Details</h2>' . $this->definitionList([
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
            . '</p></div></section>';
    }

    /**
     * @param array<int, mixed> $findings
     */
    private function contents(array $findings): string
    {
        $html = '<section class="toc page-break"><p class="eyebrow">Report navigation</p><h2>Table of Contents</h2><ul>'
            . '<li>Executive Dashboard</li><li>A. Findings (' . count($findings) . ')</li>'
            . '<li>B. Domain Scorecard</li><li>C. Exceptions</li><li>D. Patches</li>'
            . '<li>E. Store &amp; System Checks</li><li>F. Scan Details</li></ul></section>';

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
            ['label' => 'Recommendations', 'value' => (string)count($findings), 'tone' => 'orange'],
            ['label' => 'Exceptions', 'value' => (string)($logs['exception_count'] ?? 0), 'tone' => 'red'],
            ['label' => 'Scan alerts', 'value' => (string)($summary['scan_error_count'] ?? 0), 'tone' => 'teal'],
            ['label' => 'Security advisories', 'value' => (string)($composer['vulnerability_count'] ?? 0), 'tone' => 'yellow'],
            ['label' => 'Applied patches', 'value' => (string)($patches['applied_count'] ?? 0), 'tone' => 'green'],
        ];

        $html = '<section class="dashboard page-break"><div class="dashboard-header"><div><p class="eyebrow">Magento Open Source</p>'
            . '<h2>Executive Dashboard</h2><p>Latest health results for ' . $this->escape((string)($report['completed_at'] ?? '')) . '</p></div>'
            . '<div class="dashboard-score ' . $this->scoreTone($score) . '"><span>Health score</span><strong>' . $score . '<small>/100</small></strong></div></div>'
            . '<table class="dashboard-card-grid" role="presentation"><tbody>';
        foreach (array_chunk($cards, 3) as $cardRow) {
            $html .= '<tr>';
            foreach ($cardRow as $card) {
                $html .= '<td class="dashboard-card ' . $this->escape($card['tone']) . '"><span>' . $this->escape($card['label'])
                    . '</span><br><strong>' . $this->escape($card['value']) . '</strong></td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table><table class="dashboard-detail-grid" role="presentation"><tbody><tr><td><h3>Recommendations by risk</h3>'
            . $this->riskGuide($counts) . '</td><td><h3>Application information</h3>'
            . $this->keyValueTable([
                'Magento version' => $application['version'] ?? null,
                'PHP version' => $magento['php_version'] ?? null,
                'Enabled modules' => $magento['enabled_module_count'] ?? null,
                'Database version' => $database['version'] ?? null,
                'Search version' => $search['version'] ?? null,
                'Redis version' => $redis['version'] ?? null,
            ]) . '</td></tr><tr><td><h3>Top recommendations</h3>'
            . $this->renderRecommendationSummary($findings) . '</td><td><h3>Search service</h3>'
            . $this->keyValueTable([
                'Search cluster status' => $search['cluster_status'] ?? null,
                'Unassigned search shards' => $search['unassigned_shards'] ?? null,
                'What this means' => isset($search['unassigned_shards']) && (int)$search['unassigned_shards'] > 0
                    ? 'Some search data is waiting to be assigned to the search service.'
                    : 'The search service has no waiting search data.',
            ]) . '</td></tr></tbody></table></section>';

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
        $shown = [];
        $index = 0;
        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $index++;
            $ruleId = trim((string)($finding['rule_id'] ?? ''));
            $title = trim((string)($finding['title'] ?? 'Finding'));
            // A rule can produce separate evidence findings (for example one per
            // EAV attribute). The executive summary should show its action once;
            // detailed findings still retain every individual evidence record.
            $key = $ruleId !== '' ? 'rule:' . $ruleId : 'title:' . strtolower($title);
            if (isset($shown[$key])) {
                continue;
            }
            $shown[$key] = true;
            $html .= '<li><span class="risk-' . strtolower((string)($finding['risk_level'] ?? 'info')) . '">'
                . $this->escape((string)($finding['risk_level'] ?? 'Info')) . '</span> '
                . $this->escape($title) . '</li>';
            if (count($shown) === 8) {
                break;
            }
        }
        return $shown === [] ? '<p>No recommendations were generated.</p>' : $html . '</ul>';
    }

    /**
     * @param mixed $value
     */
    private function formatMetric($value, string $suffix): string
    {
        return $value === null || $value === '' ? 'N/A' : (string)$value . $suffix;
    }

    private function scoreTone(int $score): string
    {
        if ($score < 50) {
            return 'score-red';
        }
        if ($score < 80) {
            return 'score-yellow';
        }

        return 'score-green';
    }

    /**
     * @param mixed $counts
     */
    private function riskGuide($counts): string
    {
        $counts = is_array($counts) ? $counts : [];
        $levels = [
            'Critical' => 'Critical runtime or security issue requiring immediate attention.',
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
        $groups = ['critical' => [], 'severe' => [], 'high' => [], 'elevated' => [], 'medium' => [], 'low' => [], 'info' => []];
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
                $html .= '<li>' . $this->escape((string)($finding['title'] ?? '')) . '</li>';
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
        $index = 0;
        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $index++;
            $references = is_array($finding['references'] ?? null) ? $finding['references'] : [];
            $commands = is_array($finding['remediation_commands'] ?? null) ? $finding['remediation_commands'] : [];
            $thresholdBasis = trim((string)($finding['threshold_basis'] ?? ''));
            $html .= '<article class="finding"><h3>' . $index . '. '
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
                ]) . '<h4>1. Finding Description</h4>' . $this->renderFindingDescription($finding)
                . $this->renderEvidenceList($finding['evidence'] ?? [])
                . '<h4>2. Expected Result</h4><p>' . $this->renderValue($finding['expected_result'] ?? null)
                . '</p><h4>3. Site Impact</h4><p>' . $this->renderValue($finding['site_impact'] ?? null)
                . '</p><h4>4. Recommendations</h4><p>' . $this->renderValue($finding['recommendation'] ?? null)
                . '</p>' . $this->renderRecommendationSupport($commands, $references, $thresholdBasis)
                . '</article>';
        }

        return $html;
    }

    /** @param array<string, mixed> $finding */
    private function renderFindingDescription(array $finding): string
    {
        $description = $this->renderValue($finding['finding_description'] ?? null);
        $cause = trim((string)($finding['root_cause'] ?? ''));
        if ($cause === '') return '<p>' . $description . '</p>';
        return '<p>' . $description . '</p><p><strong>Reason:</strong> ' . $this->escape($cause) . '</p>';
    }

    /** @param array<string, mixed> $finding */
    private function renderWhatWasTested(array $finding): string
    {
        $source = trim((string)($finding['data_source'] ?? 'Magento health-check data'));
        $tool = trim((string)($finding['tool_used'] ?? 'the configured read-only health check'));
        return $this->escape('The scan checked ' . strtolower((string)($finding['title'] ?? 'this item')) . ' using ' . $source . '. Check performed: ' . $tool);
    }

    /** @param array<int|string, mixed> $references */
    private function renderReferences(array $references): string
    {
        $html = '<ul class="metric-list">';
        foreach ($references as $label => $reference) {
            $url = is_string($reference) ? $reference : '';
            if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
            $text = is_int($label) ? $url : (string)$label;
            $html .= '<li><a href="' . $this->escape($url) . '">' . $this->escape($text) . '</a></li>';
        }
        return $html . '</ul>';
    }

    /** @param array<int, mixed> $commands @param array<int|string, mixed> $references */
    private function renderRecommendationSupport(array $commands, array $references, string $thresholdBasis): string
    {
        $commands = array_values(array_filter($commands, 'is_string'));
        $html = '';
        if ($commands !== []) {
            $html .= '<p><strong>Command:</strong></p><pre>';
            foreach ($commands as $command) $html .= $this->escape($command) . "\n";
            $html .= '</pre>';
        }
        if ($references !== []) $html .= '<p><strong>Source of truth:</strong></p>' . $this->renderReferences($references);
        if ($thresholdBasis !== '') $html .= '<p><strong>Threshold basis:</strong> ' . $this->escape($thresholdBasis) . '</p>';
        return $html;
    }

    /** @param mixed $evidence */
    private function renderEvidenceList($evidence): string
    {
        if (!is_array($evidence) || $evidence === []) return '';
        $items = [];
        $this->flattenEvidence($evidence, '', $items);
        if ($items === []) return '';
        $html = '<p><strong>Evidence:</strong></p><ul class="metric-list">';
        foreach (array_slice($items, 0, 20) as $item) $html .= '<li>' . $this->escape($item) . '</li>';
        $html .= '</ul>';
        $advisories = $evidence['advisories'] ?? [];
        if (is_array($advisories) && $advisories !== []) $html .= $this->renderComposerAdvisories($advisories);
        return $html;
    }

    /** @param array<int, mixed> $advisories */
    private function renderComposerAdvisories(array $advisories): string
    {
        $html = '<p><strong>Composer audit details:</strong></p><div class="table-wrap"><table><thead><tr><th>Package</th><th>Severity</th><th>Advisory</th><th>CVE</th><th>Issue</th><th>Affected versions</th><th>Source</th></tr></thead><tbody>';
        foreach ($advisories as $advisory) {
            if (!is_array($advisory)) continue;
            $url = (string)($advisory['url'] ?? '');
            $source = filter_var($url, FILTER_VALIDATE_URL) ? '<a href="' . $this->escape($url) . '">Advisory link</a>' : 'Not provided';
            $html .= '<tr><td>' . $this->escape((string)($advisory['package'] ?? '')) . '</td><td>'
                . $this->escape((string)($advisory['severity'] ?? '')) . '</td><td>'
                . $this->escape((string)($advisory['advisory_id'] ?? '')) . '</td><td>'
                . $this->escape((string)($advisory['cve'] ?? '')) . '</td><td>'
                . $this->escape((string)($advisory['title'] ?? '')) . '</td><td>'
                . $this->escape((string)($advisory['affected_versions'] ?? '')) . '</td><td>' . $source . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /** @param mixed $value @param string[] $items */
    private function flattenEvidence($value, string $path, array &$items): void
    {
        if (count($items) >= 20) return;
        $firstSegment = explode('.', $path)[0] ?? '';
        if (in_array($firstSegment, ['metric', 'operator', 'threshold', 'result', 'current_value', 'observed_value', 'advisories'], true)) return;
        if (!is_array($value)) {
            $label = ucfirst(str_replace('_', ' ', str_replace('.', ' / ', $path)));
            $items[] = $label . ': ' . ($value === null ? 'not available' : (is_bool($value) ? ($value ? 'yes' : 'no') : (string)$value));
            return;
        }
        foreach ($value as $key => $item) {
            $this->flattenEvidence($item, $path === '' ? (string)$key : $path . '.' . $key, $items);
            if (count($items) >= 20) return;
        }
    }

    /** @param array<string, mixed> $finding */
    private function renderVerificationSteps(array $finding): string
    {
        $tool = trim((string)($finding['tool_used'] ?? 'the same health check'));
        $expected = trim((string)($finding['expected_result'] ?? 'the required result'));
        return '<ol><li>Apply the change in a non-production environment first.</li><li>Repeat the check: ' . $this->escape($tool) . '.</li><li>Confirm the result is: ' . $this->escape($expected) . '.</li><li>Repeat the relevant storefront, admin, API, or background-job flow and confirm that the issue does not recur.</li></ol>';
    }

    /** @param array<string, mixed> $finding */
    private function severityReason(array $finding): string
    {
        $impact = trim((string)($finding['site_impact'] ?? ''));
        return $impact === '' ? 'Priority is based on the potential effect on security, customer experience, and system reliability.' : 'Priority reflects this risk: ' . $impact;
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

        $html = '<p>The table lists the exception type, frequency, source, and a short sanitized example so a developer can identify what to fix. The internal fingerprint is omitted because it is only used to group duplicate log entries.</p><div class="table-wrap"><table><thead><tr><th>Exception</th><th>Count</th><th>First seen</th><th>Last seen</th><th>Source</th><th>Example</th></tr></thead><tbody>';
        foreach ($exceptions as $exception) {
            if (!is_array($exception)) {
                continue;
            }
            $html .= '<tr><td>' . $this->escape((string)($exception['exception_type'] ?? 'Application error')) . '</td><td>'
                . $this->escape((string)($exception['count'] ?? '')) . '</td><td>'
                . $this->escape((string)($exception['first_seen'] ?? '')) . '</td><td>'
                . $this->escape((string)($exception['last_seen'] ?? '')) . '</td><td>'
                . $this->escape((string)($exception['source'] ?? '')) . '</td><td>'
                . $this->escape($this->shortText((string)($exception['sample'] ?? ''), 500)) . '</td></tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /**
     * @param array<string, mixed> $report
     */
    private function renderPatches(array $report): string
    {
        $qpt = $report['collectors']['patches']['metrics']['quality_patches_tool'] ?? [];
        $qptStatus = is_array($qpt) ? (string)($qpt['status'] ?? 'not available') : 'not available';
        $patchEvidence = $report['rule_checks']['HP-008'] ?? [];
        $status = is_array($patchEvidence) ? (string)($patchEvidence['status'] ?? 'not_checked') : 'not_checked';
        if ($status !== 'fail') {
            return '<h3>Patch verification</h3><p>No missing patch is reported by this scan. A patch can only be listed as missing after a dated comparison of this Magento release with Adobe security bulletins.</p><p><strong>Verification status:</strong> ' . $this->escape($status) . '. <strong>Quality Patches Tool:</strong> ' . $this->escape($qptStatus) . '.</p>';
        }
        return '<h3>Patch verification</h3><p>' . $this->escape((string)($patchEvidence['reason'] ?? 'A verified patch comparison reported missing patches.')) . '</p><p>Apply only the named patches after confirming compatibility in staging.</p><p><strong>Quality Patches Tool:</strong> ' . $this->escape($qptStatus) . '.</p>';
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

        $inventory = array_values(array_filter($inventory, static fn(array $extension): bool => ($extension['type'] ?? '') === 'custom_or_vendor'));
        $html = '<h3>Third-party Extension Inventory (' . count($inventory) . ' modules)</h3><p>Only non-Magento modules are shown. Versions come from Magento or Composer metadata and can be used to plan vendor upgrades. Core Magento packages and the full Composer library list are omitted.</p><div class="table-wrap"><table><thead><tr><th>Extension</th><th>Composer Package</th><th>Installed Version</th></tr></thead><tbody>';
        foreach ($inventory as $extension) {
            if (!is_array($extension)) {
                continue;
            }
            $html .= '<tr><td>' . $this->escape((string)($extension['name'] ?? '')) . '</td><td>'
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

        $html = '<h3>Optional External Data Sources</h3><p>These are optional integrations, not Magento Open Source checks. New Relic and Datadog provide monitoring data; Lighthouse provides page performance; Fastly provides CDN data; Adobe Security Scan and UCT require their own services; SWAT Cloud, Marketplace metadata, and support tickets require external accounts. No external service is contacted unless an approved adapter and credentials are configured.</p><div class="table-wrap"><table><thead><tr><th>Source</th><th>Status</th><th>Data Collected</th><th>Reason</th></tr></thead><tbody>';
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

    /** Render finding observations as reader-friendly prose instead of JSON. */
    private function renderFindingText($value): string
    {
        if ($value === null || $value === '') return 'No measured value was available.';
        if (is_bool($value)) return $value ? 'Yes.' : 'No.';
        if (is_scalar($value)) return $this->escape((string)$value);
        if (!is_array($value) || $value === []) return 'No additional detail was recorded.';
        $parts = [];
        foreach ($value as $key => $item) {
            if (in_array((string)$key, ['metric', 'operator', 'current_value', 'observed_value', 'result', 'threshold'], true)) continue;
            $label = ucfirst(str_replace('_', ' ', (string)$key));
            if (is_array($item)) {
                if ($key === 'exceptions') {
                    $descriptions = [];
                    foreach ($item as $exception) {
                        if (!is_array($exception)) continue;
                        $description = trim((string)($exception['exception_type'] ?? 'Application error'));
                        $source = trim((string)($exception['source'] ?? ''));
                        $sample = $this->shortText((string)($exception['sample'] ?? ''), 180);
                        if ($source !== '') $description .= ' from ' . $source;
                        if ($sample !== '') $description .= ': ' . $sample;
                        $descriptions[] = $description;
                        if (count($descriptions) >= 5) break;
                    }
                    $parts[] = $label . ': ' . ($descriptions === [] ? 'none' : implode(' | ', $descriptions));
                } else {
                    $count = count($item);
                    $parts[] = $label . ': ' . ($count === 0 ? 'none' : $count . ' item' . ($count === 1 ? '' : 's'));
                }
            } elseif (is_bool($item)) {
                $parts[] = $label . ': ' . ($item ? 'yes' : 'no');
            } elseif ($item !== null && $item !== '') {
                $parts[] = $label . ': ' . (string)$item;
            }
            if (count($parts) >= 8) break;
        }
        return $this->escape($parts === [] ? 'No additional detail was recorded.' : implode('. ', $parts) . '.');
    }

    private function shortText(string $value, int $limit): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        return strlen($value) > $limit ? substr($value, 0, $limit - 1) . '…' : $value;
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

    private function renderRuleChecks(array $report): string
    {
        $checks = array_filter($report['rule_checks'] ?? [], static fn($check): bool => is_array($check) && !str_starts_with((string)($check['reason'] ?? ''), 'Not evaluated automatically:'));
        $count = count($checks);
        $html = '<h3>Check coverage (' . $count . ' active checks)</h3><p>This table lists every applicable check. <strong>not_checked</strong> means the scan did not receive enough evidence to determine pass or fail; it is not a passed check and not a confirmed issue. The observation states exactly what evidence is missing. Detailed findings above contain failed checks only.</p><table><tr><th>Finding</th><th>Status</th><th>Observation</th></tr>';
        foreach ($checks as $id => $check) {
            $html .= '<tr><td>' . $this->escape((string)($check['title'] ?? '')) . '</td><td>'
                . $this->escape((string)$check['status']) . '</td><td>'
                . $this->escape((string)$check['reason']) . '</td></tr>';
        }
        return $html . '</table>';
    }

    private function styles(): string
    {
        return '@page{size:letter;margin:.7in}body{margin:0;background:#ececec;color:#353535;font:15px/1.45 Arial,sans-serif}main{max-width:8.5in;margin:20px auto;background:#fff;box-shadow:0 1px 8px #aaa}section{padding:.7in;box-sizing:border-box}footer{position:fixed;bottom:0;left:0;right:0;padding:8px 7%;border-top:1px solid #ddd;color:#777;background:#fff;font:10px Georgia,serif;z-index:2}footer span{float:right}.page-break{break-after:page}.cover{position:relative;min-height:9.6in}.brand{position:absolute;right:.7in;top:.55in;color:#1d4e89;font-weight:bold;letter-spacing:.08em;font-size:13px}.brand span{display:block;color:#f28c28;text-align:right;font-size:9px}.cover-copy{padding-top:2.7in}.eyebrow{color:#f28c28;font-weight:bold;letter-spacing:.09em;text-transform:uppercase;font-size:12px}.cover h1{font:54px/1.02 Georgia,serif;margin:0;color:#252525}.cover-line{width:100px;border-top:4px solid #f28c28;margin:30px 0}.customer{font:24px Georgia,serif;margin-bottom:4px}.report-date{margin-top:42px;color:#666}.cover-note{position:absolute;bottom:.8in;color:#777;font-style:italic}.toc{min-height:9.6in}.toc h2,h2{font:28px Georgia,serif;color:#f28c28;margin:0 0 22px}.toc ul{list-style:none;padding:0;margin:0;line-height:2.05;font-size:16px}.toc .sub-item{margin-left:28px;font-size:14px;color:#555}h3{font:22px Georgia,serif;margin:28px 0 14px;color:#333}h4{font-size:15px;margin:22px 0 6px;color:#444}p{margin:0 0 14px}.score{font:48px Georgia,serif;color:#1d4e89;margin:14px 0}.score span{font-size:20px;color:#666}table{border-collapse:collapse;width:100%;margin:10px 0 18px;break-inside:avoid}th,td{border:1px solid #999;padding:9px;vertical-align:top;text-align:left}th{background:#f5f4f9;text-transform:uppercase;font-size:12px}td:first-child{font-weight:bold}.key-value td:first-child{width:42%}.risk-severe{color:#aa1f1f}.risk-high{color:#bd5a00}.risk-elevated{color:#896b00}.finding,.exception{border-top:2px solid #ddd;padding:18px 0 26px;break-inside:avoid}.finding:first-of-type{border-top:0;padding-top:0}.finding h3{color:#1d4e89}.finding h4{color:#f28c28}.table-wrap{overflow:auto}.metric-list{margin:0;padding-left:18px}.metric-list li{margin:2px 0}.metric-list strong{font-weight:bold}.dashboard-header{display:flex;justify-content:space-between;gap:24px;border-bottom:1px solid #ddd;padding-bottom:16px}.dashboard-header h2{margin-bottom:5px}.dashboard-score{background:#f5f8fb;border-left:5px solid #1d4e89;padding:12px 18px;min-width:120px}.dashboard-score span{display:block;font-size:11px;text-transform:uppercase;color:#666}.dashboard-score strong{display:block;font:34px Georgia,serif;color:#1d4e89}.dashboard-score small{font:16px Arial;color:#666}.dashboard-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:20px 0}.dashboard-card{border-top:4px solid #1d4e89;background:#f7f7f7;padding:14px}.dashboard-card span{display:block;color:#666;font-size:12px}.dashboard-card strong{display:block;font:28px Georgia,serif;margin-top:8px}.dashboard-card.orange{border-color:#f28c28}.dashboard-card.red{border-color:#c94c4c}.dashboard-card.purple{border-color:#625ed4}.dashboard-card.teal{border-color:#3db5b5}.dashboard-card.yellow{border-color:#e0b400}.dashboard-columns{display:grid;grid-template-columns:1fr 1fr;gap:24px}.dashboard-columns>div{min-width:0}.recommendation-list{padding-left:20px}.recommendation-list li{margin:8px 0}.recommendation-list span{font-weight:bold;margin-right:4px}dl{display:grid;grid-template-columns:minmax(160px,260px) 1fr;gap:8px 16px;margin:0}dt{font-weight:bold}dd{margin:0;min-width:0}pre{white-space:pre-wrap;word-break:break-word;margin:0;font:11px/1.35 monospace;max-width:100%}@media print{body{background:#fff}main{max-width:none;margin:0;box-shadow:none}}@media screen and (max-width:720px){main{margin:0;box-shadow:none}section{padding:28px 18px}.cover{min-height:680px}.cover-copy{padding-top:180px}.cover h1{font-size:42px}.key-value td:first-child{width:35%}.dashboard-columns{grid-template-columns:1fr}.dashboard-cards{grid-template-columns:repeat(2,1fr)}}';
    }
}
