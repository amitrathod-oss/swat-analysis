<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Finding;

/**
 * Immutable normalized health finding.
 */
class Finding
{
    /**
     * @var array<string, mixed>
     */
    private array $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function getRuleId(): string
    {
        return (string)$this->data['rule_id'];
    }

    public function getRiskLevel(): string
    {
        return (string)$this->data['risk_level'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
