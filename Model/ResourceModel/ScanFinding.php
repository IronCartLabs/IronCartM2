<?php

/**
 * IronCart_Scan — ScanFinding resource model.
 *
 * Backs IronCart\Scan\Model\ScanFinding with the `ironcart_scan_finding`
 * table.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ScanFinding extends AbstractDb
{
    public const TABLE = 'ironcart_scan_finding';
    public const ID_FIELD = 'entity_id';

    protected function _construct(): void
    {
        $this->_init(self::TABLE, self::ID_FIELD);
    }
}
