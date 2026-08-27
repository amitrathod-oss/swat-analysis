<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Finding;

use DateTimeInterface;

class FindingFactory
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Finding
    {
        foreach (['rule_id', 'title', 'issue_type', 'risk_level'] as $requiredField) {
            if (empty($data[$requiredField])) {
                throw new \InvalidArgumentException(sprintf('Finding field "%s" is required.', $requiredField));
            }
        }

        $data += [
            'tool_used' => 'Magento Health Analyzer',
            'data_source' => 'Magento Open Source',
            'last_checked' => (new \DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'finding_description' => '',
            'expected_result' => '',
            'observed_result' => null,
            'category' => 'General',
            'domain' => 'Application',
            'root_cause' => '',
            'preconditions' => [],
            'references' => [],
            'scoring_penalty' => 0,
            'site_impact' => '',
            'evidence' => [],
            'recommendation' => '',
        ];

        return new Finding($data);
    }
}
