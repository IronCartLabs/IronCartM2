<?php

/**
 * IronCart_Scan — per-row "View" action column for the scan-run listing.
 *
 * Emits a single `view` action per row, linking to the detail-view
 * controller `ironcartscan/scans/view/id/<entity_id>`. The actions
 * column XML in `ironcartscan_run_listing.xml` declares this class as
 * its column component; Magento's `actions` cell template reads the
 * data array we build here.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class ViewAction extends Column
{
    /**
     * Admin route for the detail-view controller.
     */
    public const URL_PATH = 'ironcartscan/scans/view';

    /**
     * @param ContextInterface     $context
     * @param UiComponentFactory   $uiComponentFactory
     * @param UrlInterface         $urlBuilder        Admin URL builder (injected for testability).
     * @param array<string,mixed>  $components
     * @param array<string,mixed>  $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
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
            $id = $item['entity_id'] ?? null;
            if ($id === null) {
                continue;
            }
            $item[$field]['view'] = [
                'href'  => $this->urlBuilder->getUrl(self::URL_PATH, ['id' => $id]),
                'label' => __('View'),
            ];
        }
        unset($item);

        return $dataSource;
    }
}
