<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Test\Unit\Report;

use Mha\HealthCheck\Model\ScanResult;
use Mha\HealthCheck\Report\HtmlReportGenerator;
use Mha\HealthCheck\Report\ReportDataBuilder;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use PHPUnit\Framework\TestCase;

class HtmlReportGeneratorTest extends TestCase
{
    public function testGenerateRendersRequiredSectionsAndEscapesFindingText(): void
    {
        $varDirectory = $this->createMock(WriteInterface::class);
        $filesystem = $this->createMock(Filesystem::class);
        $reportDataBuilder = $this->createMock(ReportDataBuilder::class);
        $filesystem->method('getDirectoryWrite')->with(DirectoryList::VAR_DIR)->willReturn($varDirectory);
        $reportDataBuilder->method('build')->willReturn([
            'application' => ['platform' => 'Magento Open Source', 'version' => '2.4.7'],
            'summary' => ['findings_total' => 1, 'scan_error_count' => 0, 'collector_statuses' => ['success' => 1]],
            'scan_metadata' => ['score_disclaimer' => 'Custom score', 'score_algorithm' => 'Fixed deductions'],
            'health_score' => 90,
            'health_score_details' => [],
            'severity_counts' => ['high' => 1],
            'developer_action_plan' => [
                'status' => 'Action required',
                'message' => 'One urgent issue needs developer attention.',
                'buckets' => [
                    'fix_now' => ['label' => 'Fix now', 'items' => [[
                        'owner' => 'Magento developer / Security',
                        'finding' => [
                            'rule_id' => 'TEST-001',
                            'title' => '<script>alert(1)</script>',
                            'site_impact' => 'Security risk',
                            'recommendation' => 'Update the package',
                            'observed_result' => 'Affected version',
                        ],
                    ]]],
                    'plan_next' => ['label' => 'Plan next', 'items' => []],
                    'backlog' => ['label' => 'Backlog', 'items' => []],
                ],
            ],
            'findings' => [[
                'rule_id' => 'TEST-001',
                'title' => '<script>alert(1)</script>',
                'risk_level' => 'High',
                'evidence' => [],
            ]],
            'collectors' => [],
        ]);

        $html = (new HtmlReportGenerator($filesystem, $reportDataBuilder))->generate(new ScanResult('scan-id'));

        self::assertStringContainsString('Developer Action Plan', $html);
        self::assertStringContainsString('Fix now (1)', $html);
        self::assertStringContainsString('A. Findings', $html);
        self::assertStringContainsString('F. Scan Details', $html);
        self::assertStringNotContainsString('not Adobe', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert', $html);
    }
}
