<?php

/**
 * IronCart_Scan — Magento root path resolver.
 *
 * Wraps {@see DirectoryList} so checks can ask for the BP (project root) and
 * conventional subpaths (`app/etc/env.php`, `pub/media`, `var/`) without each
 * one re-implementing the join. Read-only — never returns paths derived from
 * user input.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Filesystem;

use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Resolve well-known Magento filesystem paths for filesystem-posture checks.
 */
class MagentoRoot
{
    public function __construct(private readonly DirectoryList $directoryList)
    {
    }

    /**
     * Absolute path to the Magento project root (BP).
     */
    public function path(): string
    {
        return $this->directoryList->getRoot();
    }

    /**
     * Absolute path to `app/etc/env.php`.
     */
    public function envPhp(): string
    {
        return $this->join('app/etc/env.php');
    }

    /**
     * Absolute path to a subpath beneath the project root.
     */
    public function join(string $relative): string
    {
        $relative = ltrim($relative, '/');

        return rtrim($this->path(), '/') . '/' . $relative;
    }
}
