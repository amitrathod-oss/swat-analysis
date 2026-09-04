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
            'findings' => [[
                'rule_id' => 'TEST-001',
                'title' => '<script>alert(1)</script>',
                'risk_level' => 'High',
                'evidence' => [],
            ]],
            'collectors' => [],
        ]);

        $html = (new HtmlReportGenerator($filesystem, $reportDataBuilder))->generate(new ScanResult('scan-id'));

        self::assertStringContainsString('Executive Dashboard', $html);
        self::assertStringContainsString('A. Findings', $html);
        self::assertStringContainsString('F. Scan Details', $html);
        self::assertStringNotContainsString('not Adobe', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert', $html);
    }

    public function testTopRecommendationsDeduplicatesRepeatedRuleEvidence(): void
    {
        $varDirectory = $this->createMock(WriteInterface::class);
        $filesystem = $this->createMock(Filesystem::class);
        $reportDataBuilder = $this->createMock(ReportDataBuilder::class);
        $filesystem->method('getDirectoryWrite')->willReturn($varDirectory);
        $reportDataBuilder->method('build')->willReturn([
            'summary' => [], 'scan_metadata' => [], 'health_score' => 100,
            'findings' => [
                ['rule_id' => 'PERF-ATTR-001', 'title' => 'Attribute Has Excessive Option Count', 'risk_level' => 'Elevated', 'evidence' => ['attribute' => 'first']],
                ['rule_id' => 'PERF-ATTR-001', 'title' => 'Attribute Has Excessive Option Count', 'risk_level' => 'Elevated', 'evidence' => ['attribute' => 'second']],
                ['rule_id' => 'DB-001', 'title' => 'Large MySQL Table', 'risk_level' => 'Elevated', 'evidence' => []],
            ],
            'collectors' => [],
        ]);

        $html = (new HtmlReportGenerator($filesystem, $reportDataBuilder))->generate(new ScanResult('scan-id'));
        $summary = strstr($html, '<h3>Top recommendations</h3>');
        $summary = substr((string)$summary, 0, (int)strpos((string)$summary, '<h3>Storage and services</h3>'));

        self::assertSame(1, substr_count($summary, 'Attribute Has Excessive Option Count'));
        self::assertSame(1, substr_count($summary, 'Large MySQL Table'));
    }
}
