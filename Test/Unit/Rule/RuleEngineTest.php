<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Test\Unit\Rule;

use Sigma\HealthCheck\Finding\FindingFactory;
use Sigma\HealthCheck\Rule\RuleEngine;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class RuleEngineTest extends TestCase
{
    private RuleEngine $ruleEngine;

    protected function setUp(): void
    {
        $this->ruleEngine = new RuleEngine(new FindingFactory());
    }

    public function testEvaluateMatchesWildcardMetricAndCreatesFinding(): void
    {
        $rules = [[
            'id' => 'DB-001',
            'title' => 'Large MySQL Table',
            'issue_type' => 'Performance',
            'risk_level' => 'Elevated',
            'metric' => 'database.tables.*.size_mb',
            'operator' => 'greater_than',
            'threshold' => 1024,
        ]];

        $findings = $this->ruleEngine->evaluate([
            'database' => [
                'tables' => [
                    'catalog_product_entity' => ['size_mb' => 20],
                    'sales_order' => ['size_mb' => 2048],
                ],
            ],
        ], $rules, new DateTimeImmutable('2026-08-24T11:00:00+05:30'));

        self::assertCount(1, $findings);
        self::assertSame('DB-001', $findings[0]->getRuleId());
        self::assertSame('database.tables.sales_order.size_mb', $findings[0]->toArray()['evidence']['metric']);
        self::assertSame(2048, $findings[0]->toArray()['evidence']['current_value']);
    }

    public function testEvaluateSupportsRequiredOperators(): void
    {
        $rules = [
            $this->rule('equals', 5, 'equals'),
            $this->rule('not_equals', 4, 'not_equals'),
            $this->rule('greater_than', 4, 'greater_than'),
            $this->rule('greater_than_or_equal', 5, 'greater_than_or_equal'),
            $this->rule('less_than', 6, 'less_than'),
            $this->rule('less_than_or_equal', 5, 'less_than_or_equal'),
            $this->rule('contains', 'needle', 'contains', 'needle in a haystack'),
            $this->rule('not_contains', 'missing', 'not_contains', 'needle in a haystack'),
            $this->rule('is_true', null, 'is_true', true),
            $this->rule('is_false', null, 'is_false', false),
            $this->rule('count_greater_than', 1, 'count_greater_than', ['first', 'second']),
        ];

        $findings = [];
        foreach ($rules as $rule) {
            $findings = array_merge($findings, $this->ruleEngine->evaluate(
                ['metric' => ['value' => $rule['test_value']]],
                [$rule],
                new DateTimeImmutable()
            ));
        }

        self::assertCount(11, $findings);
    }

    public function testEvaluateSupportsExistsAndNotExists(): void
    {
        $findings = $this->ruleEngine->evaluate(
            ['database' => ['version' => '10.6']],
            [
                $this->rule('exists', null, 'exists', '10.6', 'database.version'),
                $this->rule('not_exists', null, 'not_exists', null, 'database.redis_version'),
            ],
            new DateTimeImmutable()
        );

        self::assertCount(2, $findings);
    }

    public function testUnavailableNumericMeasurementDoesNotCreateFinding(): void
    {
        $findings = $this->ruleEngine->evaluate(
            ['metric' => ['value' => null]],
            [[
                'id' => 'RULE-NULL',
                'title' => 'Unavailable metric',
                'issue_type' => 'Test',
                'risk_level' => 'Elevated',
                'metric' => 'metric.value',
                'operator' => 'less_than',
                'threshold' => 50,
            ]],
            new DateTimeImmutable()
        );

        self::assertCount(0, $findings);
    }

    /**
     * @param mixed $threshold
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function rule(string $id, $threshold, string $operator, $value = 5, string $metric = 'metric.value'): array
    {
        return [
            'id' => 'RULE-' . $id,
            'title' => 'Rule ' . $id,
            'issue_type' => 'Test',
            'risk_level' => 'Info',
            'metric' => $metric,
            'operator' => $operator,
            'threshold' => $threshold,
            'test_value' => $value,
        ];
    }
}
