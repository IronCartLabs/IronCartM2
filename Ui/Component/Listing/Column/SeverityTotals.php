<?php

/**
 * IronCart_Scan — admin grid renderer for the per-row severity totals.
 *
 * Consumes the `severity_totals` array that {@see \IronCart\Scan\Ui\DataProvider\ScanRunDataProvider}
 * synthesises from `summary_json`. Emits a compact comma-separated
 * pill list (`C:2 H:1 M:0 L:0 I:0`) so the run-listing grid renders a
 * useful summary without claiming a dedicated column per severity.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Ui\Component\Listing\Column;

use IronCart\Scan\Report\Severity;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class SeverityTotals extends Column
{
    /**
     * Severity → single-char abbreviation used in the pill label.
     *
     * @var array<string,string>
     */
    private const ABBREV = [
        Severity::CRITICAL => 'C',
        Severity::HIGH     => 'H',
        Severity::MEDIUM   => 'M',
        Severity::LOW      => 'L',
        Severity::INFO     => 'I',
    ];

    /**
     * Severity → CSS class for the abbreviation pill.
     *
     * @var array<string,string>
     */
    private const PILL_CLASS = [
        Severity::CRITICAL => 'grid-severity-critical',
        Severity::HIGH     => 'grid-severity-major',
        Severity::MEDIUM   => 'grid-severity-minor',
        Severity::LOW      => 'grid-severity-notice',
        Severity::INFO     => 'grid-severity-notice',
    ];

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
            $item[$field] = is_array($totals) ? $this->renderPills($totals) : '';
        }
        unset($item);

        return $dataSource;
    }

    /**
     * Build the comma-separated pill markup. Severities with zero
     * count still appear so the layout doesn't jiggle row-by-row.
     *
     * @param array<string,mixed> $totals
     */
    private function renderPills(array $totals): string
    {
        $parts = [];
        foreach (Severity::ALL as $severity) {
            $count = (int)($totals[$severity] ?? 0);
            $abbrev = self::ABBREV[$severity] ?? strtoupper($severity[0] ?? '?');
            $class = self::PILL_CLASS[$severity] ?? 'grid-severity-notice';
            $parts[] = sprintf(
                '<span class="%s" title="%s"><span>%s:%d</span></span>',
                $class,
                htmlspecialchars($severity, ENT_QUOTES, 'UTF-8'),
                $abbrev,
                $count
            );
        }
        return implode(' ', $parts);
    }
}
