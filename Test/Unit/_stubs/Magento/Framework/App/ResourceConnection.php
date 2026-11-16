<?php

/**
 * Unit-test stub of Magento\Framework\App\ResourceConnection (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Framework\App;

use Magento\Framework\DB\Adapter\AdapterInterface;

class ResourceConnection
{
    public const DEFAULT_CONNECTION = 'default';

    /**
     * @param string $resourceName
     * @return AdapterInterface
     */
    public function getConnection($resourceName = self::DEFAULT_CONNECTION)
    {
        throw new \LogicException('Stub: mock getConnection() in the test.');
    }

    /**
     * @param string|string[] $modelEntity
     * @param string $connectionName
     * @return string
     */
    public function getTableName($modelEntity, $connectionName = self::DEFAULT_CONNECTION)
    {
        return is_array($modelEntity) ? implode('_', $modelEntity) : $modelEntity;
    }
}
