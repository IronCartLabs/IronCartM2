<?php

/**
 * Unit-test stub of Magento\Framework\DB\Select (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Framework\DB;

/**
 * Flattened: the real class extends Zend_Db_Select, which declares these.
 */
class Select
{
    /**
     * @param array<string, string>|string $name
     * @param array<int|string, string>|string $cols
     * @param string|null $schema
     * @return $this
     */
    public function from($name, $cols = '*', $schema = null)
    {
        return $this;
    }

    /**
     * @param array<string, string>|string $name
     * @param string $cond
     * @param array<int|string, string>|string $cols
     * @param string|null $schema
     * @return $this
     */
    public function joinLeft($name, $cond, $cols = '*', $schema = null)
    {
        return $this;
    }

    /**
     * @param string $cond
     * @param mixed $value
     * @param int|string|null $type
     * @return $this
     */
    public function where($cond, $value = null, $type = null)
    {
        return $this;
    }
}
