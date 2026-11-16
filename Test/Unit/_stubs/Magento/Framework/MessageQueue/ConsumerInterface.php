<?php

/**
 * Unit-test stub of Magento\Framework\MessageQueue\ConsumerInterface (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Framework\MessageQueue;

interface ConsumerInterface
{
    /**
     * @param int|null $maxNumberOfMessages
     * @return void
     */
    public function process($maxNumberOfMessages = null);
}
