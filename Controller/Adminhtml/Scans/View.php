<?php

/**
 * IronCart_Scan — admin detail-view controller for a single scan run.
 *
 * Reached via `ironcartscan/scans/view/id/<entity_id>` from the
 * "View" action on the run-listing grid. Renders the findings
 * UI Component (`ironcartscan_finding_listing`) scoped to the
 * requested run via the `id` route param.
 *
 * Severity narrowing is handled entirely by the standard Magento
 * column-filter UI on the findings grid (see issue #106). No
 * controller-side session plumbing is required — admin users select
 * one or more severities from the severity-column dropdown filter.
 *
 * ACL: gated by `IronCart_Scan::view` — the same resource the
 * landing controller uses. There is no separate "read findings"
 * resource at v1; the run-listing and findings grids are part of
 * the same surface from a permissions perspective.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Controller\Adminhtml\Scans;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

class View extends Action implements HttpGetActionInterface
{
    /**
     * ACL resource required to view this page.
     */
    public const ADMIN_RESOURCE = 'IronCart_Scan::view';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Render the findings detail page for the run id in the URL.
     */
    public function execute(): ResultInterface
    {
        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('IronCart_Scan::scans');

        $runId = (int)$this->getRequest()->getParam('id', 0);
        $title = $runId > 0
            ? __('Scan Run #%1 — Findings', $runId)
            : __('Scan Run — Findings');
        $resultPage->getConfig()->getTitle()->prepend($title);

        return $resultPage;
    }
}
