<?php
declare(strict_types=1);

namespace Asiamarket\HealthCheck\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;

class Download extends Action
{
    public const ADMIN_RESOURCE = 'Asiamarket_HealthCheck::dashboard';
    private const PDF_PATH = 'health-reports/latest.pdf';
    private FileFactory $fileFactory;
    private ReadInterface $varDirectory;

    public function __construct(Context $context, FileFactory $fileFactory, Filesystem $filesystem)
    {
        parent::__construct($context);
        $this->fileFactory = $fileFactory;
        $this->varDirectory = $filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
    }

    public function execute()
    {
        if (!$this->varDirectory->isExist(self::PDF_PATH)) {
            $this->messageManager->addErrorMessage(__('No PDF report is available. Run the health scan with --format=pdf first.'));
            return $this->resultRedirectFactory->create()->setPath('healthcheck/dashboard/index');
        }

        return $this->fileFactory->create(
            'asian-market-health-report.pdf',
            ['type' => 'filename', 'value' => self::PDF_PATH],
            DirectoryList::VAR_DIR,
            'application/pdf'
        );
    }
}
