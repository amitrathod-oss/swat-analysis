<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Rule;

use Sigma\HealthCheck\Finding\Finding;
use Sigma\HealthCheck\Finding\FindingFactory;
use DateTimeInterface;

class RuleEngine
{
    private FindingFactory $findingFactory;

    public function __construct(FindingFactory $findingFactory)
    {
        $this->findingFactory = $findingFactory;
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<int, array<string, mixed>> $rules
     * @return Finding[]
     */
    public function evaluate(array $metrics, array $rules, DateTimeInterface $lastChecked): array
    {
        $findings = [];
        foreach ($rules as $rule) {
            $metric = (string)$rule['metric'];
            $operator = (string)$rule['operator'];
            $matches = $this->resolveMetric($metrics, explode('.', $metric));

            if ($operator === 'not_exists') {
                if ($matches === []) {
                    $findings[] = $this->createFinding($rule, $metric, null, $lastChecked);
                }
                continue;
            }

            if ($operator === 'exists') {
                if ($matches !== []) {
                    foreach ($matches as $path => $value) {
                        $findings[] = $this->createFinding($rule, $path, $value, $lastChecked);
                    }
                }
                continue;
            }

            foreach ($matches as $path => $value) {
                if ($this->matches($value, $operator, $rule['threshold'] ?? null)) {
                    $findings[] = $this->createFinding($rule, $path, $value, $lastChecked);
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $rule
     * @param mixed $value
     */
    private function createFinding(array $rule, string $path, $value, DateTimeInterface $lastChecked): Finding
    {
        $threshold = $rule['threshold'] ?? null;

        return $this->findingFactory->create([
            'rule_id' => (string)$rule['id'],
            'title' => (string)$rule['title'],
            'issue_type' => (string)$rule['issue_type'],
            'risk_level' => (string)$rule['risk_level'],
            'category' => (string)($rule['category'] ?? 'General'),
            'domain' => (string)($rule['domain'] ?? $rule['category'] ?? 'Application'),
            'tool_used' => (string)($rule['tool_used'] ?? 'Magento Health Analyzer'),
            'data_source' => (string)($rule['data_source'] ?? 'Magento Open Source'),
            'last_checked' => $lastChecked->format(DateTimeInterface::ATOM),
            'finding_description' => (string)($rule['finding_description'] ?? sprintf(
                'Metric "%s" matched the configured %s condition.',
                $path,
                $rule['operator']
            )),
            'expected_result' => (string)($rule['expected_result'] ?? 'The metric should remain within the configured threshold.'),
            'observed_result' => $value,
            'root_cause' => (string)($rule['root_cause'] ?? 'The observed metric matched the configured rule condition.'),
            'preconditions' => $rule['preconditions'] ?? [],
            'references' => $rule['references'] ?? [],
            'scoring_penalty' => (int)($rule['scoring_penalty'] ?? 0),
            'site_impact' => (string)($rule['site_impact'] ?? 'This condition may affect the reliability or performance of the site.'),
            'evidence' => [
                'metric' => $path,
                'current_value' => $value,
                'threshold' => $threshold,
                'operator' => (string)$rule['operator'],
            ],
            'recommendation' => (string)($rule['recommendation'] ?? 'Investigate the metric and its contributing workload.'),
        ]);
    }

    /**
     * @param mixed $value
     * @param mixed $threshold
     */
    private function matches($value, string $operator, $threshold): bool
    {
        // A missing measurement is not the same as numeric zero. Collectors use
        // null for unavailable or non-comparable data, which must not create a
        // false positive for threshold rules.
        if ($value === null && in_array($operator, [
            '>', 'greater_than', '>=', 'greater_than_or_equal',
            '<', 'less_than', '<=', 'less_than_or_equal',
        ], true)) {
            return false;
        }

        switch ($operator) {
            case '=':
            case '==':
            case 'equals':
                return $this->valuesEqual($value, $threshold);
            case '!=':
            case 'not_equals':
                return !$this->valuesEqual($value, $threshold);
            case '>':
            case 'greater_than':
                return $value > $threshold;
            case '>=':
            case 'greater_than_or_equal':
                return $value >= $threshold;
            case '<':
            case 'less_than':
                return $value < $threshold;
            case '<=':
            case 'less_than_or_equal':
                return $value <= $threshold;
            case 'contains':
                return $this->contains($value, $threshold);
            case 'not_contains':
                return !$this->contains($value, $threshold);
            case 'is_true':
                return $value === true;
            case 'is_false':
                return $value === false;
            case 'count_greater_than':
                return (is_countable($value) ? count($value) : (int)$value) > (int)$threshold;
            case 'regex':
                return is_string($value) && is_string($threshold) && @preg_match($threshold, $value) === 1;
            default:
                throw new \InvalidArgumentException(sprintf('Unsupported health rule operator "%s".', $operator));
        }
    }

    /**
     * @param mixed $left
     * @param mixed $right
     */
    private function valuesEqual($left, $right): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            return (float)$left === (float)$right;
        }

        return $left === $right;
    }

    /**
     * @param mixed $value
     * @param mixed $needle
     */
    private function contains($value, $needle): bool
    {
        if (is_array($value)) {
            return in_array($needle, $value, true);
        }

        return is_string($value) && is_scalar($needle) && str_contains($value, (string)$needle);
    }

    /**
     * @param array<string, mixed> $metrics
     * @param string[] $segments
     * @return array<string, mixed>
     */
    private function resolveMetric(array $metrics, array $segments, string $path = ''): array
    {
        if ($segments === []) {
            return [$path => $metrics];
        }

        $segment = array_shift($segments);
        if ($segment === '*') {
            $matches = [];
            foreach ($metrics as $key => $value) {
                if (!is_array($value)) {
                    if ($segments === []) {
                        $matches[$this->appendPath($path, (string)$key)] = $value;
                    }
                    continue;
                }
                $matches += $this->resolveMetric($value, $segments, $this->appendPath($path, (string)$key));
            }
            return $matches;
        }

        if (!array_key_exists($segment, $metrics)) {
            return [];
        }

        $value = $metrics[$segment];
        $nextPath = $this->appendPath($path, $segment);
        if ($segments === []) {
            return [$nextPath => $value];
        }

        return is_array($value) ? $this->resolveMetric($value, $segments, $nextPath) : [];
    }

    private function appendPath(string $path, string $segment): string
    {
        return $path === '' ? $segment : $path . '.' . $segment;
    }
}
