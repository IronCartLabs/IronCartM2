<?php

/**
 * IronCart_Scan — admin scans landing controller.
 *
 * Shell controller for the v1 admin UI: renders the empty "Security Scans"
 * page that the listing UI (next issue) and the run-now action (final issue)
 * will hang off. Read-only and gated by the `IronCart_Scan::scan` ACL
 * resource declared in {@see etc/acl.xml}.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Controller\Adminhtml\Scans;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Backend\Model\View\Result\PageFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * Renders the admin "Security Scans" landing page.
 *
 * v1 returns an empty Page result; subsequent issues bind a listing UI
 * component to this controller.
 */
class Index extends Action implements HttpGetActionInterface
{
    /**
     * ACL resource required to view this page.
     *
     * Magento's backend action framework checks this against the current
     * admin user's role before invoking {@see self::execute()}.
     */
    public const ADMIN_RESOURCE = 'IronCart_Scan::scan';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Render the landing page.
     */
    public function execute(): ResultInterface
    {
        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        // Magento's framework only calls addDefaultHandle() during
        // Page::renderResult(), AFTER execute() returns. setActiveMenu() below
        // calls $layout->getBlock('menu') which forces the layout to build
        // *now* — and without the 'default' handle the menu block from
        // Magento_Backend/view/adminhtml/layout/default.xml never gets
        // generated, so getBlock returns false and setActive() crashes.
        $resultPage->addDefaultHandle();
        $resultPage->setActiveMenu('IronCart_Scan::scans');
        $resultPage->getConfig()->getTitle()->prepend(__('Security Scans'));

        return $resultPage;
    }
}
