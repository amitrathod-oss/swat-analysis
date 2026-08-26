<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Test\Unit\Rule;

use Sigma\HealthCheck\Config\HealthCheckConfig;
use Sigma\HealthCheck\Rule\RuleLoader;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use PHPUnit\Framework\TestCase;

class RuleLoaderTest extends TestCase
{
    public function testLoadReadsAndValidatesYamlRuleDefinitions(): void
    {
        $moduleDirReader = $this->createMock(ModuleDirReader::class);
        $fileDriver = $this->createMock(File::class);
        $config = $this->createMock(HealthCheckConfig::class);
        $definitionDirectory = '/tmp/HealthCheck/Rule/definitions';
        $definitionFile = $definitionDirectory . '/database.yaml';

        $moduleDirReader->expects(self::once())
            ->method('getModuleDir')
            ->with('', 'Sigma_HealthCheck')
            ->willReturn('/tmp/HealthCheck');
        $fileDriver->method('isDirectory')->with($definitionDirectory)->willReturn(true);
        $fileDriver->method('readDirectory')->with($definitionDirectory)->willReturn([$definitionFile]);
        $fileDriver->method('fileGetContents')->with($definitionFile)->willReturn(<<<'YAML'
rules:
  - id: DB-001
    title: Large MySQL Table
    issue_type: Performance
    risk_level: Elevated
    metric: database.tables.*.size_mb
    operator: greater_than
    threshold: "{{ thresholds.large_table_mb }}"
YAML
        );
        $config->method('get')->with('thresholds.large_table_mb', '{{ thresholds.large_table_mb }}')->willReturn(1024);

        $rules = (new RuleLoader($moduleDirReader, $fileDriver, $config))->load();

        self::assertCount(1, $rules);
        self::assertSame('DB-001', $rules[0]['id']);
        self::assertSame(1024, $rules[0]['threshold']);
    }

    public function testLoadReturnsNoRulesWhenDefinitionsDirectoryDoesNotExist(): void
    {
        $moduleDirReader = $this->createMock(ModuleDirReader::class);
        $fileDriver = $this->createMock(File::class);
        $config = $this->createMock(HealthCheckConfig::class);

        $moduleDirReader->method('getModuleDir')->willReturn('/tmp/HealthCheck');
        $fileDriver->method('isDirectory')->willReturn(false);

        self::assertSame([], (new RuleLoader($moduleDirReader, $fileDriver, $config))->load());
    }
}
