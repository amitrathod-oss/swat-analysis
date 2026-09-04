<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Rule;

use Mha\HealthCheck\Finding\Finding;
use Mha\HealthCheck\Finding\FindingFactory;
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
                    $findings[] = $this->createFinding($rule, $metric, null, $lastChecked, $metrics);
                }
                continue;
            }

            if ($operator === 'exists') {
                if ($matches !== []) {
                    foreach ($matches as $path => $value) {
                        $findings[] = $this->createFinding($rule, $path, $value, $lastChecked, $metrics);
                    }
                }
                continue;
            }

            foreach ($matches as $path => $value) {
                if ($this->matches($value, $operator, $rule['threshold'] ?? null)) {
                    $findings[] = $this->createFinding($rule, $path, $value, $lastChecked, $metrics);
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $rule
     * @param mixed $value
     * @param array<string, mixed> $metrics
     */
    private function createFinding(array $rule, string $path, $value, DateTimeInterface $lastChecked, array $metrics = []): Finding
    {
        $threshold = $rule['threshold'] ?? null;
        $catReason = null;
        $catDetails = [];

        if (str_starts_with($path, 'catalogue.')) {
            $parts = explode('.', $path);
            $ruleId = $parts[1] ?? (string)($rule['id'] ?? '');
            if (isset($metrics['catalogue'][$ruleId]) && is_array($metrics['catalogue'][$ruleId])) {
                $catInfo = $metrics['catalogue'][$ruleId];
                if (!empty($catInfo['reason'])) {
                    $catReason = (string)$catInfo['reason'];
                }
                if (isset($catInfo['details']) && is_array($catInfo['details'])) {
                    $catDetails = $catInfo['details'];
                }
            }
        }

        $description = $catReason ?? (string)($rule['finding_description'] ?? sprintf(
            'Metric "%s" matched the configured %s condition.',
            $path,
            $rule['operator']
        ));

        $evidence = array_merge([
            'metric' => $path,
            'current_value' => $value,
            'threshold' => $threshold,
            'operator' => (string)$rule['operator'],
        ], $catDetails);

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
            'finding_description' => $description,
            'expected_result' => (string)($rule['expected_result'] ?? 'The metric should remain within the configured threshold.'),
            'observed_result' => $value,
            'root_cause' => (string)($rule['root_cause'] ?? $this->generateRootCause($rule, $path, $value)),
            'preconditions' => $rule['preconditions'] ?? [],
            'references' => $rule['references'] ?? [],
            'scoring_penalty' => (int)($rule['scoring_penalty'] ?? 0),
            'site_impact' => (string)($rule['site_impact'] ?? 'This condition may affect the reliability or performance of the site.'),
            'evidence' => $evidence,
            'recommendation' => (string)($rule['recommendation'] ?? 'Investigate the metric and its contributing workload.'),
        ]);
    }

    /**
     * @param array<string, mixed> $rule
     * @param mixed $value
     */
    private function generateRootCause(array $rule, string $path, $value): string
    {
        $id = (string)($rule['id'] ?? '');
        $category = strtolower((string)($rule['category'] ?? ''));
        $title = (string)($rule['title'] ?? '');

        if (str_starts_with($path, 'database.tables.')) {
            $parts = explode('.', $path);
            $tableName = $parts[2] ?? $path;
            if (str_contains($tableName, 'operation')) {
                return 'Bulk operation history accumulates without automated cleanup cron execution or retention policy.';
            }
            if (str_contains($tableName, 'log') || str_contains($tableName, 'report')) {
                return 'Historical log and report tables grow due to high visitor traffic and unconfigured log rotation settings.';
            }
            if (str_contains($tableName, 'quote') || str_contains($tableName, 'cart')) {
                return 'Abandoned guest shopping carts and customer quote records remain un-purged in the database.';
            }
            if (str_contains($tableName, 'changelog') || str_contains($tableName, 'cl')) {
                return 'High volume of entity edits combined with infrequent or stalled indexer cron runs.';
            }
            return sprintf('Table "%s" accumulated records over time due to high transaction volume or missing cleanup retention.', $tableName);
        }

        if (str_starts_with($path, 'logs.exceptions.')) {
            return 'Uncaught application exceptions occur repeatedly due to custom module bugs, third-party integration failures, or missing input validation.';
        }

        if (str_starts_with($path, 'logs.system.')) {
            return 'System runtime errors generated by background workers, template rendering issues, or service disconnects.';
        }

        if (str_contains($path, 'indexer') || str_contains(strtolower($title), 'indexer')) {
            return 'Indexer status is invalid or suspended due to process timeout, high concurrent catalog updates, or missing cron execution.';
        }

        if (str_contains($path, 'cron') || str_contains(strtolower($title), 'cron')) {
            return 'Magento cron service is not scheduled in system crontab, stalled due to long-running tasks, or experiencing environment errors.';
        }

        if (str_contains($path, 'search') || str_contains(strtolower($title), 'search') || str_contains(strtolower($title), 'opensearch')) {
            return 'Search engine service (OpenSearch/Elasticsearch) is unreachable, degraded, or misconfigured in Magento backend settings.';
        }

        if (str_contains($path, 'redis') || str_contains($path, 'cache') || str_contains(strtolower($title), 'cache')) {
            return 'Cache backend (Redis/Valkey) connection dropped, memory capacity exhausted, or cache instance un-configured.';
        }

        if (str_contains($path, 'permission') || str_contains(strtolower($title), 'permission')) {
            return 'File system ownership or access permissions drift from standard Magento deployment web-user policy.';
        }

        if ($category === 'security' || str_starts_with($id, 'SEC-')) {
            return 'Configuration does not meet security hardening policy, exposing admin routes, sensitive parameters, or insecure cookie attributes.';
        }

        if ($category === 'database' || str_starts_with($id, 'DB-')) {
            return 'Database schema inconsistency, missing foreign key constraints, or un-optimized query performance schema.';
        }

        if ($category === 'performance' || str_starts_with($id, 'PERF-')) {
            return 'Resource bottleneck, missing opcode/HTTP cache configuration, or inefficient database/EAV structure.';
        }

        return sprintf('The observed metric "%s" matched the configured %s condition.', $path, $rule['operator'] ?? 'rule');
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
