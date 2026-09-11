<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Report;

use Mha\HealthCheck\Config\HealthCheckConfig;

class HealthScoreCalculator
{
    /**
     * @var array<string, int>
     */
    private const DEFAULT_DEDUCTIONS = [
        'critical' => 20,
        'severe' => 20,
        'high' => 10,
        'elevated' => 5,
        'medium' => 2,
        'low' => 1,
        'info' => 0,
    ];

    private HealthCheckConfig $config;

    public function __construct(HealthCheckConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<int, array<string, mixed>> $findings
     * The score is the percentage of severity-weighted completed rule-check
     * points that passed. Rules without enough evidence are excluded instead of
     * being treated as pass or fail.
     *
     * @param array<string, array<string, mixed>> $ruleChecks
     * @return array<string, mixed>
     */
    public function calculate(array $findings, array $ruleChecks = []): array
    {
        $startingScore = min(100, $this->config->getPositiveInt('score.starting_score', 100));
        $weights = $this->getWeights();
        $counts = array_fill_keys(array_keys(self::DEFAULT_DEDUCTIONS), 0);
        $deductions = array_fill_keys(array_keys(self::DEFAULT_DEDUCTIONS), 0);
        $rawTotalDeduction = 0;
        $seenEvidence = [];

        foreach ($findings as $finding) {
            $severity = strtolower((string)($finding['risk_level'] ?? ''));
            if (!array_key_exists($severity, $weights)) {
                continue;
            }
            $counts[$severity]++;
            $rawPenalty = (int)($finding['scoring_penalty'] ?? 0) > 0
                ? (int)$finding['scoring_penalty'] : $weights[$severity];
            $rawTotalDeduction += $rawPenalty;
            $deductions[$severity] += $rawPenalty;
            $ruleId = (string)($finding['rule_id'] ?? '');
            $metric = is_array($finding['evidence'] ?? null) ? (string)($finding['evidence']['metric'] ?? '') : '';
            $evidenceKey = $ruleId !== '' && $metric !== '' ? $ruleId . '|' . $metric : '';
            if ($evidenceKey !== '') $seenEvidence[$evidenceKey] = true;
        }

        $passed = 0;
        $failed = 0;
        $notChecked = 0;
        $passedWeight = 0;
        $failedWeight = 0;
        $domains = [];
        foreach ($ruleChecks as $check) {
            if (!is_array($check)) continue;
            $status = (string)($check['status'] ?? 'not_checked');
            $severity = strtolower((string)($check['risk_level'] ?? 'low'));
            $weight = max(1, (int)($weights[$severity] ?? 1));
            $domain = (string)($check['domain'] ?? 'Application');
            $domains[$domain] = $domains[$domain] ?? ['score' => null, 'passed' => 0, 'failed' => 0, 'not_checked' => 0, 'checked' => 0, 'passed_weight' => 0, 'failed_weight' => 0];
            if ($status === 'pass') {
                $passed++;
                $passedWeight += $weight;
                $domains[$domain]['passed']++;
                $domains[$domain]['checked']++;
                $domains[$domain]['passed_weight'] += $weight;
            } elseif ($status === 'fail') {
                $failed++;
                $failedWeight += $weight;
                $domains[$domain]['failed']++;
                $domains[$domain]['checked']++;
                $domains[$domain]['failed_weight'] += $weight;
            } else {
                $notChecked++;
                $domains[$domain]['not_checked']++;
            }
        }

        $checked = $passed + $failed;
        $completedWeight = $passedWeight + $failedWeight;
        $score = $completedWeight > 0 ? (int)round($startingScore * $passedWeight / $completedWeight) : $startingScore;
        foreach ($domains as $domain => $details) {
            $domainWeight = $details['passed_weight'] + $details['failed_weight'];
            $domains[$domain]['score'] = $domainWeight > 0
                ? (int)round(100 * $details['passed_weight'] / $domainWeight) : null;
        }

        return [
            'score' => max(0, min(100, $score)),
            'starting_score' => $startingScore,
            'total_deduction' => $startingScore - $score,
            'raw_total_deduction' => $rawTotalDeduction,
            'unique_issue_count' => count($seenEvidence) > 0 ? count($seenEvidence) : count($findings),
            'scored_finding_count' => $failed,
            'checked_count' => $checked,
            'passed_count' => $passed,
            'failed_count' => $failed,
            'not_checked_count' => $notChecked,
            'passed_weight' => $passedWeight,
            'failed_weight' => $failedWeight,
            'completed_weight' => $completedWeight,
            'score_method' => 'severity_weighted_completed_check_pass_rate',
            'score_explanation' => $completedWeight > 0
                ? sprintf('%d of %d completed checks passed. Passed checks earned %d of %d severity-weighted points. %d check%s without enough evidence %s excluded.', $passed, $checked, $passedWeight, $completedWeight, $notChecked, $notChecked === 1 ? '' : 's', $notChecked === 1 ? 'was' : 'were')
                : 'No checks produced a pass or fail result, so the configured starting score is shown.',
            'severity_counts' => $counts,
            'deductions' => $deductions,
            'deduction_weights' => $weights,
            'domain_scores' => $domains,
            'priority_counts' => [
                'P0' => $counts['critical'] + $counts['severe'],
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
