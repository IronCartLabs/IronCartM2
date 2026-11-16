<?php

/**
 * Unit-test stub of Magento\Framework\DB\Adapter\AdapterInterface (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Framework\DB\Adapter;

use Magento\Framework\DB\Select;

/**
 * Partial: the real interface has ~100 methods; only these are used.
 */
interface AdapterInterface
{
    /**
     * @return Select
     */
    public function select();

    /**
     * @param string|Select $sql
     * @param mixed $bind
     * @param mixed $fetchMode
     * @return array<int, mixed>
     */
    public function fetchAll($sql, $bind = [], $fetchMode = null);

    /**
     * @param string|Select $sql
     * @param mixed $bind
     * @return array<int, mixed>
     */
    public function fetchCol($sql, $bind = []);
}
