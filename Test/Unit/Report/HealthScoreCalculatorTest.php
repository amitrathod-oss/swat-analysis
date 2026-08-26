<?php
declare(strict_types=1);

namespace Asiamarket\HealthCheck\Test\Unit\Report;

use Asiamarket\HealthCheck\Config\HealthCheckConfig;
use Asiamarket\HealthCheck\Report\HealthScoreCalculator;
use PHPUnit\Framework\TestCase;

class HealthScoreCalculatorTest extends TestCase
{
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
