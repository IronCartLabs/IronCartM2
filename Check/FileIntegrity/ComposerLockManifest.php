<?php

/**
 * IronCart_Scan — IC-072 composer-lock manifest value object.
 *
 * In-memory representation of one
 * `etc/manifests/composer-sha1-community-<version>.json` file: the edition +
 * version it pins, and a `<vendor>/<package>` → expected `dist.shasum` (SHA-1
 * hex) map of every package recorded in a clean `composer.lock` at that
 * Magento Open Source version.
 *
 * Manifests are produced by `bin/build-composer-manifest.php` (driven by
 * `make composer-manifests`) — they are NOT shipped by Adobe. The manifest
 * is ours, derived deterministically from a clean
 * `composer create-project magento/project-community-edition:<version>`.
 * See [docs/manifests.md](../../docs/manifests.md) and
 * IronCartLabs/IronCartM2#50 for the build procedure and decision rationale.
 *
 * Read-only — instances are immutable.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\FileIntegrity;

/**
 * Immutable manifest of expected composer `dist.shasum` (SHA-1) values for
 * one (edition, version) pair.
 */
final class ComposerLockManifest
{
    /**
     * @param string                $edition  Magento edition the manifest targets — `community` only for v2
     * @param string                $version  Magento version string (e.g. `2.4.7-p5`)
     * @param array<string,string>  $entries  `<vendor>/<package>` => SHA-1 hex (lowercase)
     */
    public function __construct(
        private readonly string $edition,
        private readonly string $version,
        private readonly array $entries
    ) {
    }

    public function edition(): string
    {
        return $this->edition;
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * Number of packages pinned by this manifest.
     */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Resolve the expected SHA-1 for a package, or null if the package is
     * not in the reference manifest (i.e. third-party / marketplace).
     */
    public function expectedShaFor(string $package): ?string
    {
        return $this->entries[$package] ?? null;
    }

    /**
     * Iterable over `[package, expected_sha1]` pairs.
     *
     * @return iterable<string,string>
     */
    public function entries(): iterable
    {
        return $this->entries;
    }
}
