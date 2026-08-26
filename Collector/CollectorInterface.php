<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Collector;

/**
 * Collects raw, read-only metrics for the health analyzer.
 */
interface CollectorInterface
{
    /**
     * Return the stable collector identifier used by rules and reports.
     */
    public function getCode(): string;

    /**
     * Return whether this collector can run in the current scan context.
     * Context is intentionally an array so the module remains usable without
     * coupling collectors to a particular command or framework container.
     *
     * @param array<string, mixed> $context
     */
    public function isSupported(array $context = []): bool;

    /**
     * Return raw metrics without evaluating their severity.
     *
     * @return array<string, mixed>
     */
    public function collect(array $context = []): array;
}
