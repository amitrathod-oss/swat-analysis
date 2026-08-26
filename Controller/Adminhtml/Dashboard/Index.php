<?php
declare(strict_types=1);

namespace Asiamarket\HealthCheck\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Asiamarket_HealthCheck::dashboard';
    private PageFactory $pageFactory;

    public function __construct(Context $context, PageFactory $pageFactory)
    {
        parent::__construct($context);
        $this->pageFactory = $pageFactory;
    }

    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Asiamarket_HealthCheck::dashboard');
        $page->getConfig()->getTitle()->prepend(__('Health Check Dashboard'));
        return $page;
    }
}
