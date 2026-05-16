<?php

/**
 * IronCart_Scan — admin grid renderer for `ironcart_scan_finding.severity`.
 *
 * Mirrors {@see StatusBadge} but maps the Ironcart severity vocabulary
 * (critical / high / medium / low / info) onto Magento's built-in
 * `grid-severity-*` classes. Unknown / future severities fall back
 * to the `notice` class rather than crashing the grid.
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

class SeverityBadge extends Column
{
    /**
     * @var array<string,string>
     */
    private const SEVERITY_CLASS = [
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
            $value = (string)($item[$field] ?? '');
            $class = self::SEVERITY_CLASS[$value] ?? 'grid-severity-notice';
            $label = htmlspecialchars(ucfirst($value), ENT_QUOTES, 'UTF-8');
            $item[$field] = sprintf('<span class="%s"><span>%s</span></span>', $class, $label);
        }
        unset($item);

        return $dataSource;
    }
}
