<?php
declare(strict_types=1);

namespace Asiamarket\HealthCheck\Report;

use Asiamarket\HealthCheck\Model\ScanResult;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Serialize\SerializerInterface;

class JsonReportGenerator
{
    private const REPORT_DIRECTORY = 'health-reports';
    private const LATEST_REPORT = self::REPORT_DIRECTORY . '/latest.json';

    private WriteInterface $varDirectory;
    private SerializerInterface $serializer;
    private ReportDataBuilder $reportDataBuilder;
    private ?HistoryManager $historyManager;

    public function __construct(
        Filesystem $filesystem,
        SerializerInterface $serializer,
        ReportDataBuilder $reportDataBuilder,
        ?HistoryManager $historyManager = null
    ) {
        $this->varDirectory = $filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $this->serializer = $serializer;
        $this->reportDataBuilder = $reportDataBuilder;
        $this->historyManager = $historyManager;
    }

    public function generate(ScanResult $scanResult): string
    {
        return (string)$this->serializer->serialize($this->reportDataBuilder->build($scanResult));
    }

    /**
     * Write the generated report only within Magento's var directory.
     */
    public function write(ScanResult $scanResult): string
    {
        $report = $this->reportDataBuilder->build($scanResult);
        $this->varDirectory->create(self::REPORT_DIRECTORY);
        $encoded = (string)$this->serializer->serialize($report);
        $this->varDirectory->writeFile(self::LATEST_REPORT, $encoded);
        if ($this->historyManager !== null && $scanResult->isHistoryEnabled()) {
            $this->historyManager->record($report);
        }

        return 'var/' . self::LATEST_REPORT;
    }

    public function writeTo(ScanResult $scanResult, string $path): string
    {
        $report = $this->reportDataBuilder->build($scanResult);
        $this->writeExternal($path, (string)$this->serializer->serialize($report));
        if ($this->historyManager !== null && $scanResult->isHistoryEnabled()) {
            $this->historyManager->record($report);
        }
        return $path;
    }

    private function writeExternal(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create report directory "%s".', $directory));
        }
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException(sprintf('Unable to write report "%s".', $path));
        }
    }
}
