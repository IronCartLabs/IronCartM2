<?php

/**
 * IronCart_Scan — admin detail-view controller for a single scan run.
 *
 * Reached via `ironcartscan/scans/view/id/<entity_id>` from the
 * "View" action on the run-listing grid. Renders the findings
 * UI Component (`ironcartscan_finding_listing`) scoped to the
 * requested run via the `id` route param.
 *
 * Also persists the per-page-render "show all severities" flag into
 * the admin backend session so the findings grid's data-provider
 * AJAX request can read it. The XHR fired by `Magento_Ui/js/grid/
 * provider` does NOT inherit arbitrary query-string params from the
 * parent page URL — only the dataSource's `requestFieldName` is
 * forwarded. The session bucket is the per-request hand-off (see
 * issue #97 root cause).
 *
 * Authority is always the URL on the most recent page render: the
 * controller writes truthy *or* falsy to the session on every
 * execute(), so a fresh navigation that lacks `?showAll=1`
 * (e.g. opening the detail view from the run-listing) restores the
 * critical-only default. There is no cross-navigation leakage.
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

use IronCart\Scan\Ui\DataProvider\ScanFindingDataProvider;
use IronCart\Scan\Ui\DataProvider\ShowAllFlag;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Session as BackendSession;
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
        private readonly PageFactory $resultPageFactory,
        private readonly BackendSession $backendSession
    ) {
        parent::__construct($context);
    }

    /**
     * Render the findings detail page for the run id in the URL.
     */
    public function execute(): ResultInterface
    {
        $this->persistShowAllFlag();

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

    /**
     * Mirror the page URL's `?showAll` param into the admin session
     * bucket {@see ShowAllFlag::SESSION_KEY}. Called unconditionally
     * (truthy *and* falsy URLs both write) so the most recent page
     * render is authoritative — a stale `true` cannot leak across a
     * subsequent fresh navigation. The findings grid's data-provider
     * AJAX request reads from the same bucket.
     */
    private function persistShowAllFlag(): void
    {
        $isShowingAll = ShowAllFlag::isTruthy(
            $this->getRequest()->getParam(ScanFindingDataProvider::SHOW_ALL_PARAM)
        );
        $this->backendSession->setData(ShowAllFlag::SESSION_KEY, $isShowingAll);
    }
}
