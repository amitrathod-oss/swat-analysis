<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Test\Unit\Report;

use Mha\HealthCheck\Finding\FindingFactory;
use Mha\HealthCheck\Config\HealthCheckConfig;
use Mha\HealthCheck\Model\ScanResult;
use Mha\HealthCheck\Report\HealthScoreCalculator;
use Mha\HealthCheck\Report\ReportDataBuilder;
use Mha\HealthCheck\Security\SecretSanitizer;
use PHPUnit\Framework\TestCase;

class ReportDataBuilderTest extends TestCase
{
    public function testBuildAddsScoreSummaryApplicationAndSanitizesOutput(): void
    {
        $scoreCalculator = $this->createMock(HealthScoreCalculator::class);
        $config = $this->createMock(HealthCheckConfig::class);
        $scoreCalculator->method('calculate')->willReturn([
            'score' => 90,
            'starting_score' => 100,
            'total_deduction' => 10,
            'severity_counts' => ['high' => 1],
            'deductions' => ['high' => 10],
            'deduction_weights' => ['high' => 10],
        ]);
        $scanResult = new ScanResult('scan-id');
        $scanResult->addCollectorResult('magento', [
            'status' => 'success',
            'metrics' => ['version' => '2.4.7', 'edition' => 'Community', 'database_password' => 'secret-value'],
        ]);
        $scanResult->addFinding((new FindingFactory())->create([
            'rule_id' => 'SEC-001',
            'title' => 'Test finding',
            'issue_type' => 'Security',
            'risk_level' => 'High',
        ]));
        $scanResult->complete();

        $config->method('get')->willReturn('Test value');
        $report = (new ReportDataBuilder($scoreCalculator, new SecretSanitizer(), $config))->build($scanResult);

        self::assertSame(90, $report['health_score']);
        self::assertSame('2.4.7', $report['application']['version']);
        self::assertSame(1, $report['summary']['findings_total']);
        self::assertSame('[REDACTED]', $report['collectors']['magento']['metrics']['database_password']);
        self::assertArrayNotHasKey('score_disclaimer', $report['scan_metadata']);
        self::assertSame('Test value', $report['report_profile']['customer_name']);
    }
}
