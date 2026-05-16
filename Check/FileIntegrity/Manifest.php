<?php

/**
 * IronCart_Scan — IC-070 file-integrity manifest value object.
 *
 * In-memory representation of one `etc/manifests/magento-core-<edition>-<version>.json`
 * file: the edition + version it pins, the hash algorithm used, and the
 * `relative_path => expected_hash` map of every file under the Magento Open
 * Source source tree at that tag.
 *
 * Manifests are produced by `bin/build-manifest.php` (driven by `make
 * manifests`) — they are NOT shipped by Adobe. The manifest is ours, derived
 * deterministically from the public `magento/magento2` git tags. See
 * docs/manifests.md for the build procedure and IronCartLabs/IronCartM2#47
 * for the decision rationale.
 *
 * Read-only — instances are immutable.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\FileIntegrity;

/**
 * Immutable manifest of expected file hashes for one (edition, version) pair.
 */
final class Manifest
{
    /**
     * @param string                $edition   Magento edition the manifest targets — `community` only for v2
     * @param string                $version   Magento version string (e.g. `2.4.7-p5`)
     * @param string                $algorithm Hash algorithm used — `sha256` for v2; reserved for future `sha1` cells
     * @param array<string,string>  $entries   `relative_path => expected_hash` (lowercase hex)
     */
    public function __construct(
        private readonly string $edition,
        private readonly string $version,
        private readonly string $algorithm,
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

    public function algorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * Number of files pinned by this manifest.
     */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Iterable over `[relativePath, expectedHash]` pairs. Lets callers walk
     * the manifest without copying the underlying array.
     *
     * @return iterable<string,string>
     */
    public function entries(): iterable
    {
        return $this->entries;
    }
}
