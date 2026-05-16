<?php

/**
 * IronCart_Scan — admin grid renderer for `ironcart_scan_run.status`.
 *
 * Wraps the raw status string in a `<span class="grid-severity-...">`
 * so the existing Magento admin grid stylesheet renders a coloured
 * pill (queued = grey, running = blue, succeeded = green, failed = red).
 * We reuse Magento's `grid-severity-*` classes rather than ship a
 * stylesheet because the v1 admin scope explicitly avoids new CSS.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Ui\Component\Listing\Column;

use IronCart\Scan\Model\ScanRun;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class StatusBadge extends Column
{
    /**
     * Map a status enum to the admin grid's built-in severity colour class.
     *
     * @var array<string,string>
     */
    private const STATUS_CLASS = [
        ScanRun::STATUS_QUEUED    => 'grid-severity-notice',
        ScanRun::STATUS_RUNNING   => 'grid-severity-minor',
        ScanRun::STATUS_SUCCEEDED => 'grid-severity-notice',
        ScanRun::STATUS_FAILED    => 'grid-severity-critical',
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
     * {@inheritDoc}
     *
     * Decorate the bound column field with HTML wrapped around the
     * raw status string. Returning HTML here is the documented pattern
     * for Magento\Ui\Component\Listing\Columns\Column subclasses —
     * the bound field's `bodyTmpl` is set to `ui/grid/cells/html` in
     * the listing XML so the markup renders rather than being escaped.
     *
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
            $class = self::STATUS_CLASS[$value] ?? 'grid-severity-notice';
            $label = htmlspecialchars(ucfirst($value), ENT_QUOTES, 'UTF-8');
            $item[$field] = sprintf('<span class="%s"><span>%s</span></span>', $class, $label);
        }
        unset($item);

        return $dataSource;
    }
}
