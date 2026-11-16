<?php

/**
 * Unit-test stub of Magento\Framework\Stdlib\DateTime\DateTime (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Framework\Stdlib\DateTime;

class DateTime
{
    /**
     * @param string|null $format
     * @param int|string|null $input
     * @return string|false
     */
    public function gmtDate($format = null, $input = null)
    {
        return gmdate($format ?? 'Y-m-d H:i:s', $this->gmtTimestamp($input));
    }

    /**
     * @param int|string|null $input
     * @return int
     */
    public function gmtTimestamp($input = null)
    {
        if ($input === null) {
            return time();
        }

        return is_numeric($input) ? (int) $input : (int) strtotime((string) $input);
    }
}
