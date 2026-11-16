<?php

/**
 * Unit-test stub of Magento\Framework\App\State (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Framework\App;

class State
{
    public const PARAM_MODE = 'MAGE_MODE';
    public const MODE_DEVELOPER = 'developer';
    public const MODE_PRODUCTION = 'production';
    public const MODE_DEFAULT = 'default';

    /**
     * @return string
     */
    public function getMode()
    {
        return self::MODE_DEFAULT;
    }

    /**
     * @return string
     */
    public function getAreaCode()
    {
        return '';
    }
}
