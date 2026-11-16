<?php

/**
 * Unit-test stub of Magento\Framework\Encryption\EncryptorInterface (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Framework\Encryption;

interface EncryptorInterface
{
    /**
     * @param string $password
     * @param bool|int|string $salt
     * @return string
     */
    public function getHash($password, $salt = false);

    /**
     * @param string $data
     * @return string
     */
    public function hash($data);

    /**
     * @param string $password
     * @param string $hash
     * @return bool
     */
    public function validateHash($password, $hash);

    /**
     * @param string $password
     * @param string $hash
     * @return bool
     */
    public function isValidHash($password, $hash);

    /**
     * @param string $hash
     * @param bool $validateCount
     * @return bool
     */
    public function validateHashVersion($hash, $validateCount = false);

    /**
     * @param string $data
     * @return string
     */
    public function encrypt($data);

    /**
     * @param string $data
     * @return string
     */
    public function decrypt($data);

    /**
     * @param string $key
     * @return mixed
     */
    public function validateKey($key);
}
