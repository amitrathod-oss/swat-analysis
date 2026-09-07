<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Controller\Adminhtml\Dashboard;

use Mha\HealthCheck\Model\ScanRunner;
use Mha\HealthCheck\Report\JsonReportGenerator;
use Mha\HealthCheck\Report\PdfReportGenerator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;

class Download extends Action
{
    public const ADMIN_RESOURCE = 'Mha_HealthCheck::dashboard';
    private const PDF_PATH = 'health-reports/latest.pdf';
    private FileFactory $fileFactory;
    private ReadInterface $varDirectory;
    private ScanRunner $scanRunner;
    private JsonReportGenerator $jsonReportGenerator;
    private PdfReportGenerator $pdfReportGenerator;

    public function __construct(
        Context $context,
        FileFactory $fileFactory,
        Filesystem $filesystem,
        ScanRunner $scanRunner,
        JsonReportGenerator $jsonReportGenerator,
        PdfReportGenerator $pdfReportGenerator
    ) {
        parent::__construct($context);
        $this->fileFactory = $fileFactory;
        $this->varDirectory = $filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        $this->scanRunner = $scanRunner;
        $this->jsonReportGenerator = $jsonReportGenerator;
        $this->pdfReportGenerator = $pdfReportGenerator;
    }

    public function execute()
    {
        if (!$this->hasCurrentReport()) {
            try {
                $scanResult = $this->scanRunner->run([
                    'magento_root' => defined('BP') ? BP : getcwd(),
                    'base_url' => '',
                    'only' => [],
                    'skip' => [],
                    'trigger' => 'admin_pdf_download',
                ]);
                $this->jsonReportGenerator->write($scanResult);
                $this->pdfReportGenerator->write($scanResult);
            } catch (\Throwable $exception) {
                $this->messageManager->addErrorMessage(__('The PDF report could not be generated. Run php bin/magento health:scan --format=pdf from the Magento project root, then try again.'));
                return $this->resultRedirectFactory->create()->setPath('healthcheck/dashboard/index');
            }
        }

        if (!$this->varDirectory->isExist(self::PDF_PATH)) {
            $this->messageManager->addErrorMessage(__('The PDF report could not be generated. Check the Magento var directory permissions and try again.'));
            return $this->resultRedirectFactory->create()->setPath('healthcheck/dashboard/index');
        }

        return $this->fileFactory->create(
            'mha-health-report.pdf',
            ['type' => 'filename', 'value' => self::PDF_PATH],
            DirectoryList::VAR_DIR,
            'application/pdf'
        );
    }
    private function hasCurrentReport(): bool
    {
        if (!$this->varDirectory->isExist(self::PDF_PATH)
            || !$this->varDirectory->isExist('health-reports/latest.json')) return false;
        try {
            $report = json_decode($this->varDirectory->readFile('health-reports/latest.json'), true, 512, JSON_THROW_ON_ERROR);
            $pdf = $this->varDirectory->stat(self::PDF_PATH);
            $json = $this->varDirectory->stat('health-reports/latest.json');
            return is_array($report) && \Mha\HealthCheck\Report\RuleCoverage::isCurrent($report)
                && ($pdf['mtime'] ?? 0) >= ($json['mtime'] ?? 0);
        } catch (\Throwable $exception) {
            return false;
        }
    }

}
