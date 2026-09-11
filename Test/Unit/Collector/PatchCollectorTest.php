<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Test\Unit\Collector;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Filesystem\Driver\File;
use Mha\HealthCheck\Collector\PatchCollector;
use Mha\HealthCheck\Service\QualityPatchesCommandRunner;
use PHPUnit\Framework\TestCase;

class PatchCollectorTest extends TestCase
{
    public function testAppliedCountIsUnknownWithoutPatchManagerEvidence(): void
    {
        $metrics = $this->collector(false)->collect()['metrics'];

        self::assertNull($metrics['applied_count']);
        self::assertSame('not_verifiable_without_patch_manager', $metrics['application_verification']);
    }

    public function testZeroAppliedIsReportedOnlyWhenAnApplicationLogWasRead(): void
    {
        $metrics = $this->collector(true)->collect()['metrics'];

        self::assertSame(0, $metrics['applied_count']);
        self::assertSame('var/log/patch.log', $metrics['application_evidence']);
    }

    public function testQualityPatchesStatusProvidesConfirmedAppliedAndNotAppliedRecords(): void
    {
        $output = json_encode([
            ['Id' => 'ACSD-10000', 'Title' => 'Applied fix', 'Category' => 'Security', 'Origin' => 'Adobe', 'Status' => 'Applied', 'Details' => ''],
            ['Id' => 'ACSD-20000', 'Title' => 'Pending fix', 'Category' => 'Checkout', 'Origin' => 'Adobe', 'Status' => 'Not applied', 'Details' => ''],
            ['Id' => 'ACSD-30000', 'Title' => 'Unavailable fix', 'Category' => 'Other', 'Origin' => 'Adobe', 'Status' => 'N/A', 'Details' => ''],
        ], JSON_THROW_ON_ERROR);
        $metrics = $this->collector(true, ['exit_code' => 0, 'output' => $output, 'error_output' => ''])->collect()['metrics'];

        self::assertSame(1, $metrics['applied_count']);
        self::assertSame('ACSD-10000', $metrics['applied_patches'][0]['patch_id']);
        self::assertSame('ACSD-20000', $metrics['available_patches'][0]['patch_id']);
        self::assertSame('verified_from_quality_patches_tool_status', $metrics['application_verification']);
    }

    /** @param array<string, int|string>|null $commandResult */
    private function collector(bool $withPatchEvidence, ?array $commandResult = null): PatchCollector
    {
        $directory = $this->createMock(DirectoryList::class);
        $directory->method('getRoot')->willReturn('/project');
        $file = $this->createMock(File::class);
        $file->method('isExists')->willReturnCallback(static function (string $path) use ($withPatchEvidence): bool {
            if ($path === '/project/composer.json') return true;
            return $withPatchEvidence && in_array($path, ['/project/vendor/bin/magento-patches', '/project/var/log/patch.log'], true);
        });
        $file->method('fileGetContents')->willReturnCallback(static fn(string $path): string => $path === '/project/composer.json' ? '{}' : '');
        $metadata = $this->createMock(ProductMetadataInterface::class);
        $metadata->method('getVersion')->willReturn('2.4.8-p5');

        $runner = $this->createMock(QualityPatchesCommandRunner::class);
        $runner->method('status')->willReturn($commandResult ?? ['exit_code' => 1, 'output' => '', 'error_output' => '']);

        return new PatchCollector($directory, $file, $metadata, $runner);
    }
}
