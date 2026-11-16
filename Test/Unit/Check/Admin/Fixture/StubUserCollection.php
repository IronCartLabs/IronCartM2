<?php

/**
 * IronCart_Scan — test fixture: minimal admin-user collection stub.
 *
 * Stands in for `\Magento\User\Model\ResourceModel\User\Collection` in unit
 * tests so checks can be exercised without booting the Magento ORM. Only the
 * surface that the IC-011/IC-012/IC-013/IC-014 checks actually use is implemented.
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
        // Scalar equality (`is_active = 1`) stays a no-op: the IC-011/012/013
        // fixtures are pre-filtered. Array conditions are applied, because
        // IC-014 relies on `created` / `modified` range filters to split
        // new users from edited ones. Values are `Y-m-d H:i:s` strings, so
        // a string compare orders them the same way MySQL does.
        if (!is_array($value)) {
            return $this;
        }

        foreach ($value as $operator => $bound) {
            $this->rows = array_values(array_filter(
                $this->rows,
                static function (DataObject $row) use ($field, $operator, $bound): bool {
                    $cmp = strcmp((string) $row->getData($field), (string) $bound);
                    return match ($operator) {
                        'gteq' => $cmp >= 0,
                        'lt' => $cmp < 0,
                        default => throw new \LogicException(
                            sprintf('StubUserCollection does not support the "%s" condition.', $operator)
                        ),
                    };
                }
            ));
        }

        return $this;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rows);
    }
}
