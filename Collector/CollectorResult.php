<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Collector;

/**
 * Normalized result envelope for a collector. Collectors still return arrays
 * for backwards compatibility, while ScanRunner normalizes every result.
 */
final class CollectorResult
{
    /**
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function success(string $collector, array $metrics, float $duration, array $meta = []): array
    {
        return [
            'collector' => $collector,
            'status' => 'success',
            'collected_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'duration_ms' => round($duration * 1000, 2),
            'metrics' => $metrics,
            'meta' => $meta,
        ];
    }
}
