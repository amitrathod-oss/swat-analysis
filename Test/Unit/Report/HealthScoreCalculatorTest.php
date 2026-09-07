<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Test\Unit\Report;

use Mha\HealthCheck\Config\HealthCheckConfig;
use Mha\HealthCheck\Report\HealthScoreCalculator;
use PHPUnit\Framework\TestCase;

class HealthScoreCalculatorTest extends TestCase
{
    public function testCriticalCsvRulesAreCountedAndScoredAsP0(): void
    {
        $config = $this->createMock(HealthCheckConfig::class);
        $config->method('getPositiveInt')->willReturn(100);
        $config->method('get')->willReturn([]);
        $result = (new HealthScoreCalculator($config))->calculate([
            ['rule_id' => 'HP-001', 'risk_level' => 'Critical'],
            ['rule_id' => 'HP-003', 'risk_level' => 'Severe'],
        ]);
        self::assertSame(60, $result['score']);
        self::assertSame(1, $result['severity_counts']['critical']);
        self::assertSame(2, $result['priority_counts']['P0']);
    }

    public function testCalculateUsesTransparentDeductionsAndClampsTheScore(): void
    {
        $config = $this->createMock(HealthCheckConfig::class);
        $config->method('getPositiveInt')->with('score.starting_score', 100)->willReturn(100);
        $config->method('get')->with('score.deductions', [])->willReturn([]);

        $result = (new HealthScoreCalculator($config))->calculate([
            ['risk_level' => 'Severe'],
            ['risk_level' => 'High'],
            ['risk_level' => 'Elevated'],
            ['risk_level' => 'Info'],
            ['risk_level' => 'Severe'],
            ['risk_level' => 'Severe'],
            ['risk_level' => 'Severe'],
            ['risk_level' => 'Severe'],
        ]);

        self::assertSame(0, $result['score']);
        self::assertSame(115, $result['total_deduction']);
        self::assertSame(5, $result['severity_counts']['severe']);
        self::assertSame(20, $result['deduction_weights']['severe']);
    }
}
