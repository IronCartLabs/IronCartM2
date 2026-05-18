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
 *   2. Lets the parent `AbstractDataProvider` apply any column-filter
 *      chips the admin user selected (severity dropdown, title text,
 *      date ranges, etc.) via the standard `addFilter()` machinery.
 *      The severity column declares `<filter>select</filter>` with
 *      `SeverityOptions` in the XML, so admin users get a standard
 *      Magento dropdown filter consistent with every other grid.
 *      Per issue #106 no default severity filter is applied — the
 *      grid shows all findings for the run on first load.
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
     * @param array<string,mixed>          $meta              UI component meta.
     * @param array<string,mixed>          $data              UI component data.
     */
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        ScanFindingCollectionFactory $collectionFactory,
        private readonly RequestInterface $request,
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
