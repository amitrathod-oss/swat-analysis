<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Controller\Adminhtml\Dashboard;

use Mha\HealthCheck\Config\HealthCheckConfig;
use Mha\HealthCheck\Model\ScanRunner;
use Mha\HealthCheck\Report\JsonReportGenerator;
use Mha\HealthCheck\Report\PdfReportGenerator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Mha_HealthCheck::dashboard';
    private PageFactory $pageFactory;
    private ReadInterface $varDirectory;
    private ScanRunner $scanRunner;
    private JsonReportGenerator $jsonReportGenerator;
    private PdfReportGenerator $pdfReportGenerator;
    private HealthCheckConfig $config;

    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        Filesystem $filesystem,
        ScanRunner $scanRunner,
        JsonReportGenerator $jsonReportGenerator,
        PdfReportGenerator $pdfReportGenerator,
        HealthCheckConfig $config
    ) {
        parent::__construct($context);
        $this->pageFactory = $pageFactory;
        $this->varDirectory = $filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        $this->scanRunner = $scanRunner;
        $this->jsonReportGenerator = $jsonReportGenerator;
        $this->pdfReportGenerator = $pdfReportGenerator;
        $this->config = $config;
    }

    public function execute(): Page
    {
        $this->generateDashboardReportIfRequired();
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Mha_HealthCheck::dashboard');
        $page->getConfig()->getTitle()->prepend(__('Health Check Dashboard'));
        return $page;
    }

    private function generateDashboardReportIfRequired(): void
    {
        $forceScan = (bool)$this->getRequest()->getParam('refresh');
        $autoScan = $this->config->get('dashboard.auto_scan_on_open', true);
        if (!$forceScan && $autoScan !== true) {
            return;
        }
        if (!$forceScan && $this->varDirectory->isExist('health-reports/latest.json')) {
            return;
        }

        try {
            $scanResult = $this->scanRunner->run([
                'magento_root' => defined('BP') ? BP : getcwd(),
                'base_url' => '',
                'only' => [],
                'skip' => [],
                'trigger' => 'admin_dashboard',
            ]);
            $this->jsonReportGenerator->write($scanResult);
            $this->pdfReportGenerator->write($scanResult);
            if ($forceScan) {
                $this->messageManager->addSuccessMessage(__('Health report refreshed successfully.'));
            }
        } catch (\Throwable $exception) {
            $message = $this->config->get(
                'dashboard.scan_error_message',
                'The health report could not be generated automatically. Run php bin/magento health:scan --format=json from the Magento project root.'
            );
            $this->messageManager->addErrorMessage(__((string)$message));
        }
    }
}
