<?php

/**
 * Unit-test stub of Magento\Cron\Model\Schedule (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Cron\Model;

/**
 * The getters below are only @method magic on the real class. They are
 * declared here so PHPUnit can configure them on a mock.
 */
class Schedule
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_MISSED = 'missed';
    public const STATUS_ERROR = 'error';

    /**
     * @return string
     */
    public function getJobCode()
    {
        return '';
    }

    /**
     * @return string
     */
    public function getStatus()
    {
        return '';
    }

    /**
     * @return string
     */
    public function getExecutedAt()
    {
        return '';
    }
}
