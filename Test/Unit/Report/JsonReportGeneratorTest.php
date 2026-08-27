<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Test\Unit\Report;

use Mha\HealthCheck\Model\ScanResult;
use Mha\HealthCheck\Report\JsonReportGenerator;
use Mha\HealthCheck\Report\ReportDataBuilder;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class JsonReportGeneratorTest extends TestCase
{
    public function testWriteCreatesLatestSanitizedJsonReport(): void
    {
        $varDirectory = $this->createMock(WriteInterface::class);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::once())
            ->method('getDirectoryWrite')
            ->with(DirectoryList::VAR_DIR)
            ->willReturn($varDirectory);
        $varDirectory->expects(self::once())->method('create')->with('health-reports');
        $varDirectory->expects(self::once())
            ->method('writeFile')
            ->with(
                'health-reports/latest.json',
                self::callback(static function (string $json): bool {
                    return str_contains($json, '[REDACTED]') && !str_contains($json, 'database-secret');
                })
            );

        $reportDataBuilder = $this->createMock(ReportDataBuilder::class);
        $reportDataBuilder->method('build')->willReturn([
            'collectors' => ['test' => ['metrics' => ['database_password' => '[REDACTED]']]],
        ]);

        $generator = new JsonReportGenerator($filesystem, new Json(), $reportDataBuilder);

        self::assertSame('var/health-reports/latest.json', $generator->write(new ScanResult('scan-id')));
    }
}
