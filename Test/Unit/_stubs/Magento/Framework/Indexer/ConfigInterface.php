<?php

/**
 * Unit-test stub of Magento\Framework\Indexer\ConfigInterface (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Framework\Indexer;

interface ConfigInterface
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function getIndexers();

    /**
     * @param string $indexerId
     * @return array<string, mixed>
     */
    public function getIndexer($indexerId);
}
