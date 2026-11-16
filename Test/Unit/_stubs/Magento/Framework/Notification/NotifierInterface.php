<?php

/**
 * Unit-test stub of Magento\Framework\Notification\NotifierInterface (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Framework\Notification;

interface NotifierInterface
{
    /**
     * @param int $severity
     * @param string $title
     * @param string|string[] $description
     * @param string $url
     * @param bool $isInternal
     * @return mixed
     */
    public function add($severity, $title, $description, $url = '', $isInternal = true);

    /**
     * @param string $title
     * @param string|string[] $description
     * @param string $url
     * @param bool $isInternal
     * @return mixed
     */
    public function addCritical($title, $description, $url = '', $isInternal = true);

    /**
     * @param string $title
     * @param string|string[] $description
     * @param string $url
     * @param bool $isInternal
     * @return mixed
     */
    public function addMajor($title, $description, $url = '', $isInternal = true);

    /**
     * @param string $title
     * @param string|string[] $description
     * @param string $url
     * @param bool $isInternal
     * @return mixed
     */
    public function addMinor($title, $description, $url = '', $isInternal = true);

    /**
     * @param string $title
     * @param string|string[] $description
     * @param string $url
     * @param bool $isInternal
     * @return mixed
     */
    public function addNotice($title, $description, $url = '', $isInternal = true);
}
