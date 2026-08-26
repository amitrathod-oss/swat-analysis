<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Report;

use Sigma\HealthCheck\Config\HealthCheckConfig;

class HealthScoreCalculator
{
    /**
     * @var array<string, int>
     */
    private const DEFAULT_DEDUCTIONS = [
        'severe' => 20,
        'high' => 10,
        'elevated' => 5,
        'medium' => 2,
        'low' => 1,
        'info' => 0,
    ];

    /** Prevent one noisy rule from deciding the entire health score. */
    private const MAX_PENALTY_PER_RULE = [
        'severe' => 30,
        'high' => 20,
        'elevated' => 15,
        'medium' => 10,
        'low' => 5,
        'info' => 0,
    ];

    private HealthCheckConfig $config;

    public function __construct(HealthCheckConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<int, array<string, mixed>> $findings
     * @return array<string, int|array<string, int>>
     */
    public function calculate(array $findings): array
    {
        $startingScore = min(100, $this->config->getPositiveInt('score.starting_score', 100));
        $weights = $this->getWeights();
        $counts = array_fill_keys(array_keys(self::DEFAULT_DEDUCTIONS), 0);
        $deductions = array_fill_keys(array_keys(self::DEFAULT_DEDUCTIONS), 0);
        $totalDeduction = 0;
        $rawTotalDeduction = 0;
        $scoredFindings = [];
        $seenEvidence = [];
        $rulePenalties = [];
        $domains = [
            'Security' => ['score' => 100, 'deduction' => 0, 'findings' => 0],
            'Availability' => ['score' => 100, 'deduction' => 0, 'findings' => 0],
            'Performance' => ['score' => 100, 'deduction' => 0, 'findings' => 0],
            'Database' => ['score' => 100, 'deduction' => 0, 'findings' => 0],
            'Application' => ['score' => 100, 'deduction' => 0, 'findings' => 0],
            'Code Quality' => ['score' => 100, 'deduction' => 0, 'findings' => 0],
            'Infrastructure' => ['score' => 100, 'deduction' => 0, 'findings' => 0],
        ];

        foreach ($findings as $index => $finding) {
            $severity = strtolower((string)($finding['risk_level'] ?? ''));
            if (!array_key_exists($severity, $weights)) {
                continue;
            }
            $counts[$severity]++;
            $rawPenalty = (int)($finding['scoring_penalty'] ?? 0) > 0
                ? (int)$finding['scoring_penalty'] : $weights[$severity];
            $rawTotalDeduction += $rawPenalty;
            $ruleId = (string)($finding['rule_id'] ?? '');
            $metric = is_array($finding['evidence'] ?? null) ? (string)($finding['evidence']['metric'] ?? '') : '';
            $evidenceKey = $ruleId !== '' && $metric !== '' ? $ruleId . '|' . $metric : '';
            if ($evidenceKey !== '' && isset($seenEvidence[$evidenceKey])) {
                continue;
            }
            if ($evidenceKey !== '') {
                $seenEvidence[$evidenceKey] = true;
            }
            $ruleKey = $ruleId !== '' ? $ruleId : 'finding-' . $index;
            $remaining = self::MAX_PENALTY_PER_RULE[$severity] - (int)($rulePenalties[$ruleKey] ?? 0);
            $penalty = min($rawPenalty, max(0, $remaining));
            if ($penalty === 0) {
                continue;
            }
            $rulePenalties[$ruleKey] = (int)($rulePenalties[$ruleKey] ?? 0) + $penalty;
            $deductions[$severity] += $penalty;
            $totalDeduction += $penalty;
            $scoredFindings[] = $finding;
            $domain = (string)($finding['domain'] ?? 'Application');
            if (!isset($domains[$domain])) {
                $domains[$domain] = ['score' => 100, 'deduction' => 0, 'findings' => 0];
            }
            $domains[$domain]['deduction'] += $weights[$severity];
            $domains[$domain]['findings']++;
        }

        foreach ($domains as $domain => $details) {
            $domains[$domain]['score'] = max(0, 100 - $details['deduction']);
        }

        return [
            'score' => max(0, min(100, $startingScore - $totalDeduction)),
            'starting_score' => $startingScore,
            'total_deduction' => $totalDeduction,
            'raw_total_deduction' => $rawTotalDeduction,
            'unique_issue_count' => count($seenEvidence) > 0 ? count($seenEvidence) : count($scoredFindings),
            'scored_finding_count' => count($scoredFindings),
            'capped_rule_count' => count(array_filter($rulePenalties, static fn(int $penalty): bool => $penalty > 0)),
            'severity_counts' => $counts,
            'deductions' => $deductions,
            'deduction_weights' => $weights,
            'domain_scores' => $domains,
            'priority_counts' => [
                'P0' => $counts['severe'],
                'P1' => $counts['high'],
                'P2' => $counts['elevated'],
                'P3' => $counts['medium'],
                'P4' => $counts['low'],
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function getWeights(): array
    {
        $configured = $this->config->get('score.deductions', []);
        if (!is_array($configured)) {
            return self::DEFAULT_DEDUCTIONS;
        }

        $weights = self::DEFAULT_DEDUCTIONS;
        foreach ($weights as $severity => $default) {
            $value = $configured[$severity] ?? $default;
            if (is_numeric($value) && (int)$value >= 0) {
                $weights[$severity] = (int)$value;
            }
        }

        return $weights;
    }
}
