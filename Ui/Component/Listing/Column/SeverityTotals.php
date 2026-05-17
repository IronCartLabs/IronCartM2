<?php

/**
 * IronCart_Scan — admin grid renderer for the per-row severity totals.
 *
 * Consumes the `severity_totals` array that {@see \IronCart\Scan\Ui\DataProvider\ScanRunDataProvider}
 * synthesises from `summary_json` and delegates the actual markup
 * generation to {@see \IronCart\Scan\Ui\Component\Listing\Column\Severity\SeverityIndicatorRow}.
 *
 * The renderer emits a horizontal 5-slot indicator row (critical / high
 * / medium / low / info) so the grid cell occupies the height of a
 * plain text column rather than stacking pills vertically. Empty
 * severities still take a slot — the column width stays constant
 * across rows and the eye can scan vertically for changes.
 *
 * The split into a Magento-free helper exists so the markup is unit-
 * testable under the v0 CI's Test/Unit/Report cell, which strips
 * magento/framework before composer install and therefore cannot load
 * this Column subclass directly.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Ui\Component\Listing\Column;

use IronCart\Scan\Ui\Component\Listing\Column\Severity\SeverityIndicatorRow;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class SeverityTotals extends Column
{
    /**
     * @param ContextInterface     $context
     * @param UiComponentFactory   $uiComponentFactory
     * @param array<string,mixed>  $components
     * @param array<string,mixed>  $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array{data:array{items:list<array<string,mixed>>}} $dataSource
     *
     * @return array<string,mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $field = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            $totals = $item['severity_totals'] ?? [];
            $item[$field] = is_array($totals) ? SeverityIndicatorRow::render($totals) : '';
        }
        unset($item);

        return $dataSource;
    }
}
