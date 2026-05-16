<?php

/**
 * IronCart_Scan — ScanFinding collection.
 *
 * Backs the detail-view UI Component grid (see
 * {@see \IronCart\Scan\Ui\DataProvider\ScanFindingDataProvider}). Findings
 * are always scoped to a parent scan run via the `scan_run_id` request
 * param; the default-severity filter is applied at the data-provider
 * layer (not here) so the collection stays a thin wrapper.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Model\ResourceModel\ScanFinding;

use IronCart\Scan\Model\ResourceModel\ScanFinding as ScanFindingResource;
use IronCart\Scan\Model\ScanFinding as ScanFindingModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = ScanFindingResource::ID_FIELD;

    protected function _construct(): void
    {
        $this->_init(ScanFindingModel::class, ScanFindingResource::class);
    }
}
