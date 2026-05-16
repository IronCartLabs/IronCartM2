<?php

/**
 * IronCart_Scan — ScanRun collection.
 *
 * Standard Magento collection over the `ironcart_scan_run` table. The
 * admin UI grid data provider in {@see \IronCart\Scan\Ui\DataProvider\ScanRunDataProvider}
 * delegates to this collection so we keep `severity totals` etc. as
 * post-load decoration rather than baking JOIN logic into the resource
 * model.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Model\ResourceModel\ScanRun;

use IronCart\Scan\Model\ResourceModel\ScanRun as ScanRunResource;
use IronCart\Scan\Model\ScanRun as ScanRunModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = ScanRunResource::ID_FIELD;

    protected function _construct(): void
    {
        $this->_init(ScanRunModel::class, ScanRunResource::class);
    }
}
