<?php

/**
 * IronCart_Scan — framework-free Magento-root walker.
 *
 * Three checks (`Check/PatchLevel/ComposerLockReader`,
 * `Check/PwaStudio/PwaStudioDetector`, `Check/Hyva/CheckoutCspRegressionCheck`)
 * historically each carried a byte-identical `__DIR__`-up-walk loop that
 * looked for `composer.lock` to identify the Magento project root. This
 * helper consolidates that walk into a single place. The framework-aware
 * sibling `Check/Filesystem/MagentoRoot` (which wraps
 * `Magento\Framework\App\Filesystem\DirectoryList::getRoot()`) is intentionally
 * separate — the helper here exists so unit tests can exercise the three
 * callers without booting the framework, and so the check classes themselves
 * stay DI-free fallbacks.
 *
 * Read-only — `is_file()` / `dirname()` only. No outbound calls, no writes.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Support;

/**
 * Framework-free helper that walks up from a starting directory looking
 * for a marker file (`composer.lock` by default) which identifies the
 * Magento project root.
 */
final class MagentoRootLocator
{
    /**
     * Conventional marker file at the Magento project root.
     */
    public const DEFAULT_MARKER = 'composer.lock';

    /**
     * Default maximum number of directory levels to inspect. Matches the
     * historical hand-rolled loops (10 iterations) — generous enough to
     * cover `app/code/<vendor>/<module>/Check/<group>` from app/code AND
     * `vendor/<vendor>/<package>/Check/<group>` from a Composer install,
     * while still bailing out before traversing an unbounded filesystem.
     */
    public const DEFAULT_MAX_DEPTH = 10;

    private function __construct()
    {
        // Static-only helper; no instances.
    }

    /**
     * Walk up from `$startDir` looking for `$marker`. Returns the directory
     * that contains the marker, or `null` if it is not found within
     * `$maxDepth` levels or the filesystem root is reached first.
     *
     * Behaviour matches the previous hand-rolled loops exactly:
     *   - the start dir is inspected first;
     *   - the loop runs up to `$maxDepth` iterations;
     *   - `dirname($dir) === $dir` (filesystem root) breaks the walk.
     */
    public static function locate(
        string $startDir,
        string $marker = self::DEFAULT_MARKER,
        int $maxDepth = self::DEFAULT_MAX_DEPTH
    ): ?string {
        $dir = $startDir;
        for ($i = 0; $i < $maxDepth; $i++) {
            if (is_file($dir . DIRECTORY_SEPARATOR . $marker)) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }
}
