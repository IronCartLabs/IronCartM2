<?php

/**
 * Unit-test stub of Magento\Store\Model\Store (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Store\Model;

class Store
{
    /**
     * @param string $type
     * @param bool|null $secure
     * @return string
     */
    public function getBaseUrl($type = 'link', $secure = null)
    {
        return '';
    }
}
