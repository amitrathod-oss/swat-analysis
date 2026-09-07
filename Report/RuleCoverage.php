<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Report;

/** Reject cached reports generated before the forty-rule reset. */
class RuleCoverage
{
    public static function isCurrent(array $report): bool
    {
        $checks = $report['rule_checks'] ?? null;
        if (!is_array($checks) || count($checks) !== 40 || !empty($report['scan_errors'])) return false;
        for ($i = 1; $i <= 40; $i++) {
            $check = $checks[sprintf('HP-%03d', $i)] ?? null;
            if (!is_array($check) || !in_array($check['status'] ?? null, ['pass', 'fail', 'not_checked'], true)) return false;
        }
        foreach ($report['findings'] ?? [] as $finding) {
            if (!is_array($finding) || !isset($checks[$finding['rule_id'] ?? ''])) return false;
        }
        return true;
    }
}
