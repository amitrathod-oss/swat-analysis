<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Test\Unit\Report;

use Mha\HealthCheck\Config\HealthCheckConfig;
use Mha\HealthCheck\Report\HealthScoreCalculator;
use PHPUnit\Framework\TestCase;

class HealthScoreCalculatorTest extends TestCase
{
    public function testCompletedCheckPassRateIsUsedAndCriticalFindingsRemainP0(): void
    {
        $config = $this->createMock(HealthCheckConfig::class);
        $config->method('getPositiveInt')->willReturn(100);
        $config->method('get')->willReturn([]);
        $result = (new HealthScoreCalculator($config))->calculate([
            ['rule_id' => 'HP-001', 'risk_level' => 'Critical'],
            ['rule_id' => 'HP-003', 'risk_level' => 'Severe'],
        ], [
            'HP-001' => ['status' => 'fail', 'risk_level' => 'Critical', 'domain' => 'Application'],
            'HP-002' => ['status' => 'pass', 'risk_level' => 'Severe', 'domain' => 'Database'],
            'HP-003' => ['status' => 'fail', 'risk_level' => 'Severe', 'domain' => 'Infrastructure'],
            'HP-004' => ['status' => 'pass', 'risk_level' => 'Severe', 'domain' => 'Security'],
        ]);
        self::assertSame(50, $result['score']);
        self::assertSame(2, $result['passed_count']);
        self::assertSame(2, $result['failed_count']);
        self::assertSame(1, $result['severity_counts']['critical']);
        self::assertSame(2, $result['priority_counts']['P0']);
    }

    public function testNotCheckedRulesAreExcludedAndDomainScoresUseTheSameFormula(): void
    {
        $config = $this->createMock(HealthCheckConfig::class);
        $config->method('getPositiveInt')->with('score.starting_score', 100)->willReturn(100);
        $config->method('get')->with('score.deductions', [])->willReturn([]);

        $result = (new HealthScoreCalculator($config))->calculate([
            ['risk_level' => 'High'],
        ], [
            'HP-001' => ['status' => 'pass', 'risk_level' => 'High', 'domain' => 'Security'],
            'HP-002' => ['status' => 'pass', 'risk_level' => 'High', 'domain' => 'Security'],
            'HP-003' => ['status' => 'fail', 'risk_level' => 'High', 'domain' => 'Security'],
            'HP-004' => ['status' => 'not_checked', 'risk_level' => 'High', 'domain' => 'Security'],
        ]);

        self::assertSame(67, $result['score']);
        self::assertSame(3, $result['checked_count']);
        self::assertSame(1, $result['not_checked_count']);
        self::assertSame(67, $result['domain_scores']['Security']['score']);
        self::assertSame('2 of 3 completed checks passed. Passed checks earned 20 of 30 severity-weighted points. 1 check without enough evidence was excluded.', $result['score_explanation']);
    }
}
