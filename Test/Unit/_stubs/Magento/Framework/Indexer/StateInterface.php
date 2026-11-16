<?php

/**
 * Unit-test stub of Magento\Framework\Indexer\StateInterface (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Framework\Indexer;

interface StateInterface
{
    public const STATUS_WORKING = 'working';
    public const STATUS_VALID = 'valid';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_SUSPENDED = 'suspended';

    /**
     * @return string
     */
    public function getIndexerId();

    /**
     * @param string $value
     * @return StateInterface
     */
    public function setIndexerId($value);

    /**
     * @return string
     */
    public function getStatus();

    /**
     * @return string
     */
    public function getUpdated();

    /**
     * @param string|int $value
     * @return StateInterface
     */
    public function setUpdated($value);

    /**
     * @param string $indexerId
     * @return StateInterface
     */
    public function loadByIndexer($indexerId);

    /**
     * @param string $status
     * @return StateInterface
     */
    public function setStatus($status);
}
