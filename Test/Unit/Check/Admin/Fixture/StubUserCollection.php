<?php

/**
 * IronCart_Scan — test fixture: minimal admin-user collection stub.
 *
 * Stands in for `\Magento\User\Model\ResourceModel\User\Collection` in unit
 * tests so checks can be exercised without booting the Magento ORM. Only the
 * surface that the IC-011/IC-012/IC-013 checks actually use is implemented.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Admin\Fixture;

use ArrayIterator;
use IteratorAggregate;
use Magento\Framework\DataObject;
use Traversable;

/**
 * @implements IteratorAggregate<int, DataObject>
 */
class StubUserCollection implements IteratorAggregate
{
    /**
     * @param list<DataObject> $rows
     */
    public function __construct(private array $rows)
    {
    }

    public function addFieldToFilter(string $field, mixed $value): self
    {
        // No-op — the production code passes `is_active = 1` and our fixtures
        // are pre-filtered. Returning $this preserves the fluent contract.
        return $this;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rows);
    }
}
