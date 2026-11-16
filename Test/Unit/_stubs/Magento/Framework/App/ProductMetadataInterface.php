<?php

/**
 * Unit-test stub of Magento\Framework\App\ProductMetadataInterface (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Framework\App;

interface ProductMetadataInterface
{
    /**
     * @return string
     */
    public function getVersion();

    /**
     * @return string
     */
    public function getEdition();

    /**
     * @return string
     */
    public function getName();
}
