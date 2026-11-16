<?php

/**
 * Unit-test stub of Magento\Framework\MessageQueue\ConsumerFactory (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Framework\MessageQueue;

class ConsumerFactory
{
    /**
     * @param string $consumerName
     * @param int $batchSize
     * @return ConsumerInterface
     */
    public function get($consumerName, $batchSize = 0)
    {
        throw new \LogicException('Stub factory: mock get() in the test.');
    }
}
