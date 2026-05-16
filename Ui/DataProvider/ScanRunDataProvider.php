<?php

/**
 * IronCart_Scan — admin grid data provider for ScanRun.
 *
 * Backs the `ironcartscan_run_listing` UI Component (see
 * view/adminhtml/ui_component/ironcartscan_run_listing.xml). The
 * provider exposes the raw `ironcart_scan_run` columns plus a derived
 * `severity_totals` payload synthesised from `summary_json` so the
 * grid can render per-row critical / high / medium / low / info counts
 * without a JOIN against `ironcart_scan_finding`.
 *
 * Why decode summary_json instead of aggregating: the ScanRunConsumer
 * already persists totals via ReportBuilder when the run reaches a
 * terminal state. Re-aggregating findings at grid-render time would
 * double-spend the same data and would also force a JOIN over a table
 * that grows linearly with checks_per_run × runs_retained. The decoded
 * shape is bounded ({severity → int}) and the grid is read-only.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Ui\DataProvider;

use IronCart\Scan\Model\ResourceModel\ScanRun\Collection as ScanRunCollection;
use IronCart\Scan\Model\ResourceModel\ScanRun\CollectionFactory as ScanRunCollectionFactory;
use IronCart\Scan\Report\Severity;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Ui\DataProvider\AbstractDataProvider;

class ScanRunDataProvider extends AbstractDataProvider
{
    /**
     * @var ScanRunCollection
     */
    protected $collection;

    /**
     * @param string                   $name                Data provider XML name attr.
     * @param string                   $primaryFieldName    Usually `entity_id`.
     * @param string                   $requestFieldName    Usually `entity_id`.
     * @param ScanRunCollectionFactory $collectionFactory   Factory for ScanRun collection.
     * @param Json                     $serializer          JSON serializer for summary_json decoding.
     * @param array<string,mixed>      $meta                UI component meta.
     * @param array<string,mixed>      $data                UI component data.
     */
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        ScanRunCollectionFactory $collectionFactory,
        private readonly Json $serializer,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $collectionFactory->create();
    }

    /**
     * {@inheritDoc}
     *
     * Decorate each row with a derived `severity_totals` array drawn
     * from `summary_json`. Rows where `summary_json` is null (queued
     * runs that never reached the consumer) get a zero-filled totals
     * map so the grid column renderer can format unconditionally.
     *
     * @return array{
     *     totalRecords:int,
     *     items:list<array<string,mixed>>
     * }
     */
    public function getData(): array
    {
        if (!$this->getCollection()->isLoaded()) {
            $this->getCollection()->load();
        }

        $items = [];
        foreach ($this->getCollection()->getItems() as $item) {
            $row = $item->getData();
            $row['severity_totals'] = $this->extractTotals($row['summary_json'] ?? null);
            $items[] = $row;
        }

        return [
            'totalRecords' => $this->getCollection()->getSize(),
            'items'        => $items,
        ];
    }

    /**
     * Decode a `summary_json` blob and return a severity → int map.
     *
     * Defensive on shape: malformed JSON, missing `totals` key, or
     * non-int values all collapse to a zero-filled map so the grid
     * column renderer never has to special-case nulls.
     *
     * @return array<string,int>
     */
    private function extractTotals(mixed $summaryJson): array
    {
        $zero = array_fill_keys(Severity::ALL, 0);
        if (!is_string($summaryJson) || $summaryJson === '') {
            return $zero;
        }

        try {
            $decoded = $this->serializer->unserialize($summaryJson);
        } catch (\Throwable) {
            return $zero;
        }
        if (!is_array($decoded) || !isset($decoded['totals']) || !is_array($decoded['totals'])) {
            return $zero;
        }

        $totals = $zero;
        foreach (Severity::ALL as $severity) {
            $value = $decoded['totals'][$severity] ?? 0;
            $totals[$severity] = is_numeric($value) ? (int)$value : 0;
        }
        return $totals;
    }
}
