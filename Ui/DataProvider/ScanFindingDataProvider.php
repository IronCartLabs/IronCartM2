<?php

/**
 * IronCart_Scan — admin grid data provider for ScanFinding (detail view).
 *
 * Backs the `ironcartscan_finding_listing` UI Component. The provider:
 *
 *   1. Scopes the collection to a single scan run via the `id` route
 *      param (`ironcartscan/scans/view/id/<entity_id>`). Without a
 *      scan_run_id the grid renders empty — by design, the detail
 *      view is never reachable without an id.
 *
 *   2. Applies a default severity filter of `severity IN (critical)`.
 *      Per the AC + parent decision (IronCartWeb#899), critical-only
 *      is the default v1 posture; admin users opt into the firehose
 *      via the "Show all severities" toggle which adds `?showAll=1`
 *      to the route. When that flag is present the default filter is
 *      lifted for the current request only — no persistence across
 *      sessions, no UI bookmark involvement.
 *
 *      The flag itself is read from the admin backend session, NOT
 *      from this AJAX request's query string. Reason: the grid's
 *      data-fetch XHR (`mui/index/render`) does not inherit query
 *      params from the parent page URL — `?showAll=1` on the page
 *      URL never reaches this provider's request scope. The detail-
 *      view controller writes the flag to the session on every page
 *      render so the value the user just opted into is the value
 *      this provider reads. See issue #97.
 *
 *   3. Truncates `detail` to 240 chars at provider time so the column
 *      renderer can stay a plain TextColumn (no per-cell PHP).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Ui\DataProvider;

use IronCart\Scan\Model\ResourceModel\ScanFinding\Collection as ScanFindingCollection;
use IronCart\Scan\Model\ResourceModel\ScanFinding\CollectionFactory as ScanFindingCollectionFactory;
use IronCart\Scan\Report\Severity;
use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\App\RequestInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;

class ScanFindingDataProvider extends AbstractDataProvider
{
    /**
     * Detail-string truncation budget. 240 chars keeps the column
     * inside a single grid row at typical admin column widths while
     * still surfacing enough context to recognise a finding.
     */
    public const DETAIL_TRUNCATE = 240;

    /**
     * Route param flipped by the "Show all severities" header button.
     * Presence of a truthy value on the *page* request (handled by
     * the detail-view controller) disables the default severity
     * filter for that page render and any AJAX grid refresh that
     * follows. Reading this directly from the AJAX request scope is
     * the bug fixed by issue #97 — use the session bucket instead.
     */
    public const SHOW_ALL_PARAM = 'showAll';

    /**
     * Route param carrying the scan-run id. Magento populates this
     * from `view/id/<n>` in the URL.
     */
    public const RUN_PARAM = 'id';

    /**
     * @var ScanFindingCollection
     */
    protected $collection;

    /**
     * @param string                       $name              Data provider XML name attr.
     * @param string                       $primaryFieldName  Usually `entity_id`.
     * @param string                       $requestFieldName  Usually `entity_id`.
     * @param ScanFindingCollectionFactory $collectionFactory Factory for ScanFinding collection.
     * @param RequestInterface             $request           Current request (for `id`).
     * @param BackendSession               $backendSession    Admin session bucket carrying the showAll flag written by the detail-view controller.
     * @param array<string,mixed>          $meta              UI component meta.
     * @param array<string,mixed>          $data              UI component data.
     */
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        ScanFindingCollectionFactory $collectionFactory,
        private readonly RequestInterface $request,
        private readonly BackendSession $backendSession,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $collectionFactory->create();
    }

    /**
     * {@inheritDoc}
     *
     * @return array{
     *     totalRecords:int,
     *     items:list<array<string,mixed>>
     * }
     */
    public function getData(): array
    {
        $this->applyScanRunFilter();
        $this->applyDefaultSeverityFilter();

        if (!$this->getCollection()->isLoaded()) {
            $this->getCollection()->load();
        }

        $items = [];
        foreach ($this->getCollection()->getItems() as $item) {
            $row = $item->getData();
            $row['detail'] = $this->truncate((string)($row['detail'] ?? ''));
            $items[] = $row;
        }

        return [
            'totalRecords' => $this->getCollection()->getSize(),
            'items'        => $items,
        ];
    }

    /**
     * Scope the collection to the scan run id from the route.
     *
     * Absent / non-numeric `id` is treated as "no run selected" — we
     * deliberately filter by `0` to return an empty grid rather than
     * the whole `ironcart_scan_finding` table.
     */
    private function applyScanRunFilter(): void
    {
        $runId = (int)$this->request->getParam(self::RUN_PARAM, 0);
        $this->getCollection()->addFieldToFilter('scan_run_id', $runId);
    }

    /**
     * Add the critical-only default filter unless the session bucket
     * carries a truthy showAll flag (written by the detail-view
     * controller on the most recent page render).
     *
     * The filter is applied via `addFieldToFilter` on the collection
     * (not as a UI Component `<filter>` default) so admin users cannot
     * inadvertently persist a bookmark that removes it. The toggle
     * lifts it per-page-render only.
     */
    private function applyDefaultSeverityFilter(): void
    {
        if ($this->isShowAllRequested()) {
            return;
        }
        $this->getCollection()->addFieldToFilter('severity', ['in' => [Severity::CRITICAL]]);
    }

    /**
     * Whether the most recent detail-view page render opted into the
     * "show all severities" mode. Reads from the admin backend
     * session bucket {@see ShowAllFlag::SESSION_KEY} that the
     * controller writes on every render — the page URL's `?showAll`
     * param is *not* forwarded to this AJAX request scope.
     *
     * Public so layout helpers can inspect the same state without
     * re-implementing the session-read path.
     */
    public function isShowAllRequested(): bool
    {
        return ShowAllFlag::isTruthy(
            $this->backendSession->getData(ShowAllFlag::SESSION_KEY)
        );
    }

    /**
     * Truncate the detail text. Ellipsis is appended only when the
     * input actually exceeded the budget so unaffected rows don't
     * gain a misleading "…".
     */
    private function truncate(string $detail): string
    {
        if ($detail === '' || mb_strlen($detail) <= self::DETAIL_TRUNCATE) {
            return $detail;
        }
        return mb_substr($detail, 0, self::DETAIL_TRUNCATE) . '…';
    }
}
