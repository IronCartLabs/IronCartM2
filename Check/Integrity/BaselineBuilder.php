<?php

/**
 * IronCart_Scan — Recon 7.1 file-integrity baseline builder.
 *
 * Walks `app/code/**`, `app/etc/**`, and `vendor/magento/**` beneath the
 * Magento project root and produces a sorted SHA-256 manifest of every
 * regular file that isn't matched by {@see IgnorePatterns}. The walk is
 * read-only, follows no symlinks (defence against `var/cache/...` re-entries
 * via symlink farms in some Magento Cloud layouts), and skips unreadable
 * files rather than aborting the whole baseline.
 *
 * The output is fed to {@see BaselineRepository::save()} on rebaseline, and
 * is the canonical reference {@see FileHashCheck} diffs against on every
 * scheduled scan.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Integrity;

use FilesystemIterator;
use IronCart\Scan\Check\Filesystem\MagentoRoot;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Builds a fresh {@see BaselineManifest} by hashing the configured roots.
 */
class BaselineBuilder
{
    /**
     * Webroot-relative directories the builder walks. The set is fixed for
     * v0 — adding new roots requires a schema bump because diff semantics
     * depend on which paths are in scope.
     *
     * @var list<string>
     */
    public const DEFAULT_ROOTS = [
        'app/code',
        'app/etc',
        'vendor/magento',
    ];

    /**
     * @param list<string> $roots Optional override for {@see DEFAULT_ROOTS} — primarily for tests.
     */
    public function __construct(
        private readonly MagentoRoot $magentoRoot,
        private readonly IgnorePatterns $ignorePatterns,
        private readonly array $roots = self::DEFAULT_ROOTS
    ) {
    }

    /**
     * Walk the configured roots and return a fresh baseline manifest.
     */
    public function build(string $magentoEdition, string $magentoVersion): BaselineManifest
    {
        $entries = [];
        $base = rtrim($this->magentoRoot->path(), '/\\');

        foreach ($this->roots as $root) {
            $absoluteRoot = $base . DIRECTORY_SEPARATOR . ltrim($root, '/');
            if (!is_dir($absoluteRoot)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $absoluteRoot,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $fileInfo) {
                /** @var \SplFileInfo $fileInfo */
                if (!$fileInfo->isFile() || $fileInfo->isLink()) {
                    continue;
                }
                $absolute = $fileInfo->getPathname();
                $relative = $this->relativise($absolute, $base);
                if ($relative === null) {
                    continue;
                }
                if ($this->ignorePatterns->matches($relative)) {
                    continue;
                }
                $hash = @hash_file(BaselineManifest::ALGORITHM_SHA256, $absolute);
                if ($hash === false) {
                    // Unreadable — record nothing rather than poison the baseline.
                    continue;
                }
                $entries[$relative] = strtolower($hash);
            }
        }

        ksort($entries);

        return new BaselineManifest(
            generatedAt: gmdate('Y-m-d\TH:i:s\Z'),
            magentoEdition: $magentoEdition,
            magentoVersion: $magentoVersion,
            algorithm: BaselineManifest::ALGORITHM_SHA256,
            roots: array_values($this->roots),
            entries: $entries
        );
    }

    /**
     * Convert an absolute path under `$base` to a forward-slashed relative
     * path. Returns `null` when the file isn't inside the base — defence
     * against iterator traversal escaping the webroot via symlinks (we
     * already skip links above, but the check is cheap).
     */
    private function relativise(string $absolute, string $base): ?string
    {
        $normalisedAbsolute = str_replace('\\', '/', $absolute);
        $normalisedBase = str_replace('\\', '/', $base);
        $prefix = rtrim($normalisedBase, '/') . '/';

        if (!str_starts_with($normalisedAbsolute, $prefix)) {
            return null;
        }

        return substr($normalisedAbsolute, strlen($prefix));
    }
}
