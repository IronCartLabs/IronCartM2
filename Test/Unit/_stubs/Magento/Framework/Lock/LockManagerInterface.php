<?php

/**
 * Unit-test stub of Magento\Framework\Lock\LockManagerInterface (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Framework\Lock;

interface LockManagerInterface
{
    public function lock(string $name, int $timeout = -1): bool;

    public function unlock(string $name): bool;

    public function isLocked(string $name): bool;
}
