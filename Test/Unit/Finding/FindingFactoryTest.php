<?php
declare(strict_types=1);

namespace Asiamarket\HealthCheck\Test\Unit\Finding;

use Asiamarket\HealthCheck\Finding\FindingFactory;
use PHPUnit\Framework\TestCase;

class FindingFactoryTest extends TestCase
{
    public function testCreateBuildsNormalizedFinding(): void
    {
        $finding = (new FindingFactory())->create([
            'rule_id' => 'DB-001',
            'title' => 'Large MySQL Table',
            'issue_type' => 'Performance',
            'risk_level' => 'Elevated',
        ]);

        $data = $finding->toArray();
        self::assertSame('DB-001', $finding->getRuleId());
        self::assertSame('Elevated', $finding->getRiskLevel());
        self::assertSame([], $data['evidence']);
        self::assertNotEmpty($data['last_checked']);
    }

    public function testCreateRejectsMissingRequiredField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rule_id');

        (new FindingFactory())->create([
            'title' => 'Missing rule id',
            'issue_type' => 'Performance',
            'risk_level' => 'Low',
        ]);
    }
}
