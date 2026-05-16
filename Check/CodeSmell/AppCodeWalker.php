<?php

/**
 * IronCart_Scan — `app/code/**\/*.php` walker for the code-smell check pack.
 *
 * Centralises the directory-walk so each individual CodeSmell check class
 * doesn't re-implement the recursive filesystem scan. Scope is
 * intentionally narrow: `<magento_root>/app/code/` only. Composer-managed
 * code under `vendor/` is covered by IC-001/IC-002 (patch level), core
 * code is covered by the file-integrity check, and `pub/`, `generated/`,
 * `var/`, `setup/` are never walked.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\CodeSmell;

use FilesystemIterator;
use IronCart\Scan\Check\Filesystem\MagentoRoot;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Walks `<magento_root>/app/code/**\/*.php`, yielding absolute file paths.
 *
 * Read-only; never follows symlinks; silently skips unreadable subtrees so
 * one permission-denied directory doesn't blow up the whole scan.
 */
class AppCodeWalker
{
    public function __construct(private readonly MagentoRoot $root)
    {
    }

    /**
     * Yield absolute paths of every `*.php` file beneath `app/code/`.
     *
     * Returns a generator so callers can stream rather than materialising
     * the full list in memory — Magento installs can have tens of thousands
     * of `app/code/**\/*.php` files once large agency vendor modules land.
     *
     * @return \Generator<int,string>
     */
    public function phpFiles(): \Generator
    {
        $appCode = $this->root->join('app/code');

        if (!is_dir($appCode)) {
            return;
        }

        try {
            // SKIP_DOTS only — do not follow symlinks. Magento's deploy
            // pipeline sometimes places generated/ symlinks back into
            // app/code/, and we'd otherwise re-walk the same files.
            $directory = new RecursiveDirectoryIterator(
                $appCode,
                FilesystemIterator::SKIP_DOTS
            );
        } catch (\UnexpectedValueException) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            $directory,
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            yield $file->getPathname();
        }
    }
}
