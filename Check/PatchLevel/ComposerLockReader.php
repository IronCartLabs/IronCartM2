<?php

/**
 * IronCart_Scan — composer.lock reader.
 *
 * Loads the project-level `composer.lock` so IC-002 can compare the
 * installed package versions against the bundled OSV advisory snapshot.
 * Read-only.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\PatchLevel;

use JsonException;
use RuntimeException;

/**
 * Reads installed-package metadata from a Magento root `composer.lock`.
 */
class ComposerLockReader
{
    /**
     * @param string|null $lockPath Override the discovered location of
     *                              `composer.lock`. Tests rely on this.
     */
    public function __construct(private readonly ?string $lockPath = null)
    {
    }

    /**
     * Return the installed package name → version map.
     *
     * Both `packages` and `packages-dev` are merged (advisories don't
     * care about dev/prod split — a vulnerable dev tool is still a
     * vulnerable file on disk).
     *
     * @return array<string,string>
     *
     * @throws RuntimeException When the lockfile cannot be located or parsed.
     */
    public function packages(): array
    {
        $path = $this->resolveLockPath();
        if ($path === null) {
            throw new RuntimeException('composer.lock not found in Magento root.');
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read "%s".', $path));
        }

        try {
            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Invalid JSON in "%s": %s', $path, $e->getMessage()), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Unexpected composer.lock shape in "%s".', $path));
        }

        $packages = [];
        foreach (['packages', 'packages-dev'] as $bucket) {
            $list = $decoded[$bucket] ?? [];
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $name = $entry['name'] ?? null;
                $version = $entry['version'] ?? null;
                if (is_string($name) && is_string($version) && $name !== '' && $version !== '') {
                    $packages[$name] = self::normaliseVersion($version);
                }
            }
        }

        return $packages;
    }

    /**
     * Resolve which `composer.lock` to read.
     *
     * - Honours an explicitly-injected `$lockPath` (used by tests).
     * - Otherwise walks up from the module's installed location to find
     *   the Magento root. The module lives at
     *   `app/code/IronCart/Scan/Check/PatchLevel/ComposerLockReader.php`
     *   when installed via `app/code`, or under `vendor/ironcartlabs/...`
     *   when installed via Composer; either way the root containing
     *   `composer.lock` is reachable by walking up.
     */
    private function resolveLockPath(): ?string
    {
        if ($this->lockPath !== null) {
            return is_file($this->lockPath) ? $this->lockPath : null;
        }

        $dir = __DIR__;
        for ($i = 0; $i < 10; $i++) {
            $candidate = $dir . DIRECTORY_SEPARATOR . 'composer.lock';
            if (is_file($candidate)) {
                return $candidate;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * Trim Composer's `vX.Y.Z` prefix so the value compares cleanly with
     * OSV advisory ranges (which omit the leading `v`).
     */
    private static function normaliseVersion(string $version): string
    {
        if ($version !== '' && ($version[0] === 'v' || $version[0] === 'V')) {
            return substr($version, 1);
        }

        return $version;
    }
}
