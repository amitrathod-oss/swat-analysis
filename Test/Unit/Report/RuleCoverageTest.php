<?php
declare(strict_types=1);
namespace Mha\HealthCheck\Test\Unit\Report;
use Mha\HealthCheck\Report\RuleCoverage;
use PHPUnit\Framework\TestCase;
class RuleCoverageTest extends TestCase
{
    public function testRejectsLegacyReportsAndAcceptsCompleteCurrentCoverage(): void
    {
        self::assertFalse(RuleCoverage::isCurrent(['metadata' => ['analyzer' => 'Mha HealthCheck']]));
        $report = ['rule_checks' => []];
        for ($i = 1; $i <= 80; $i++) $report['rule_checks'][sprintf('HP-%03d', $i)] = ['status' => 'not_checked'];
        self::assertFalse(RuleCoverage::isCurrent($report));
        unset($report['rule_checks']['HP-007']);
        self::assertTrue(RuleCoverage::isCurrent($report));
        $report['findings'] = [['rule_id' => 'SYS-001']];
        self::assertFalse(RuleCoverage::isCurrent($report));
        $report['findings'] = [['rule_id' => 'HP-001']];
        self::assertTrue(RuleCoverage::isCurrent($report));
        unset($report['rule_checks']['HP-080']);
        self::assertFalse(RuleCoverage::isCurrent($report));
    }
}
