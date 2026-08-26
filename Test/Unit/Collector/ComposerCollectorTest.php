<?php
declare(strict_types=1);

namespace Asiamarket\HealthCheck\Test\Unit\Collector;

use Asiamarket\HealthCheck\Collector\ComposerCollector;
use Asiamarket\HealthCheck\Config\HealthCheckConfig;
use Asiamarket\HealthCheck\Service\ComposerCommandRunner;
use Magento\Framework\App\Filesystem\DirectoryList;
use PHPUnit\Framework\TestCase;

class ComposerCollectorTest extends TestCase
{
    public function testCollectCountsComposerAuditAdvisoriesAndAbandonedPackages(): void
    {
        $runner = $this->createMock(ComposerCommandRunner::class);
        $directoryList = $this->createMock(DirectoryList::class);
        $config = $this->createMock(HealthCheckConfig::class);
        $directoryList->method('getRoot')->willReturn('/project');
        $config->method('getPositiveInt')->willReturn(30);
        $runner->method('version')->willReturn(['exit_code' => 0, 'output' => 'Composer version 2.8.0']);
        $runner->method('audit')->willReturn([
            'exit_code' => 1,
            'output' => json_encode([
                'advisories' => ['vendor/package' => [['advisoryId' => 'PKSA-test']]],
                'abandoned' => ['old/package' => 'new/package'],
            ]),
        ]);

        $result = (new ComposerCollector($runner, $directoryList, $config))->collect();

        self::assertSame(1, $result['metrics']['vulnerability_count']);
        self::assertSame(1, $result['metrics']['abandoned_package_count']);
        self::assertSame(['old/package'], $result['metrics']['abandoned_packages']);
    }
}
