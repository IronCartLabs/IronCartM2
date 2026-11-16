<?php

/**
 * Unit-test stub of Magento\Cron\Model\ResourceModel\Schedule\Collection (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Cron\Model\ResourceModel\Schedule;

/**
 * @implements \IteratorAggregate<int, \Magento\Cron\Model\Schedule>
 */
class Collection implements \IteratorAggregate
{
    /**
     * @param string|string[] $field
     * @param mixed $condition
     * @return $this
     */
    public function addFieldToFilter($field, $condition = null)
    {
        return $this;
    }

    /**
     * @param string $field
     * @param string $direction
     * @return $this
     */
    public function setOrder($field, $direction = 'DESC')
    {
        return $this;
    }

    /**
     * @param int $size
     * @return $this
     */
    public function setPageSize($size)
    {
        return $this;
    }

    /**
     * @param int $page
     * @return $this
     */
    public function setCurPage($page)
    {
        return $this;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator([]);
    }
}
