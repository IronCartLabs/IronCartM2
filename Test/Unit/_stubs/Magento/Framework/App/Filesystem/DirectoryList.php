<?php

/**
 * Unit-test stub of Magento\Framework\App\Filesystem\DirectoryList (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Framework\App\Filesystem;

/**
 * Flattened: the real class extends Magento\Framework\Filesystem\DirectoryList.
 */
class DirectoryList
{
    /**
     * @param string $root
     * @param array<string, mixed> $config
     */
    public function __construct(private $root, array $config = [])
    {
    }

    /**
     * @return string
     */
    public function getRoot()
    {
        return $this->root;
    }

    /**
     * @param string $code
     * @return string
     */
    public function getPath($code)
    {
        return $this->root;
    }
}
