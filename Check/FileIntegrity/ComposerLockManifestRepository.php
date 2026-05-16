<?php

/**
 * IronCart_Scan — composer-lock manifest repository for IC-072.
 *
 * Resolves a {@see ComposerLockManifest} for a (edition, version) pair by
 * reading the matching `etc/manifests/composer-sha1-community-<version>.json`
 * file from the module's own filesystem (NEVER the merchant's webroot).
 * Manifests are generated at build time by
 * `bin/build-composer-manifest.php` and committed to the repo; runtime
 * never makes outbound network calls.
 *
 * The on-disk JSON schema is:
 *
 *     {
 *       "schema_version": "v0",
 *       "edition": "community",
 *       "version": "2.4.7-p5",
 *       "source": "composer create-project magento/project-community-edition",
 *       "source_ref": "2.4.7-p5",
 *       "generated_at": "2026-05-16",
 *       "algorithm": "sha1",
 *       "entries": {
 *         "magento/framework": "abcd...90",
 *         "magento/module-catalog": "0123...ef"
 *       }
 *     }
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\FileIntegrity;

use RuntimeException;

/**
 * Loads bundled composer-lock manifests from disk and caches them per-process.
 *
 * Only Magento Open Source is supported in v2 (decided in
 * IronCartLabs/IronCartM2#50 alongside the IC-070 decision); requests for
 * `enterprise` always return null so the caller can emit an IC-073
 * informational finding for Adobe Commerce merchants. Adobe Commerce
 * coverage is deferred to v3 hosted backend (paid composer auth runs
 * server-side).
 */
class ComposerLockManifestRepository
{
    public const SCHEMA_VERSION = 'v0';

    public const ALGORITHM = 'sha1';

    /**
     * Editions the repository will attempt to resolve. Magento Open Source
     * is reported by `ProductMetadataInterface::getEdition()` as
     * `"Community"` (case-sensitive); everything else is treated as
     * unsupported and surfaced via IC-073.
     *
     * @var list<string>
     */
    public const SUPPORTED_EDITIONS = ['community'];

    /**
     * @var array<string,ComposerLockManifest|false> Cache keyed by `<edition>-<version>`; false = miss
     */
    private array $cache = [];

    /**
     * @param string|null $manifestDir Absolute path to the manifests directory. Defaults to
     *                                 `<module-root>/etc/manifests` — the bundled location.
     *                                 Overridable for tests.
     */
    public function __construct(private readonly ?string $manifestDir = null)
    {
    }

    /**
     * Resolve the manifest for a given (edition, version). Returns null
     * when no matching manifest exists — the caller is expected to emit an
     * IC-073 informational finding in that case.
     *
     * The lookup is case-insensitive on the edition string (Magento reports
     * "Community"; manifests are filed under "community").
     */
    public function find(string $edition, string $version): ?ComposerLockManifest
    {
        $editionKey = strtolower(trim($edition));
        $versionKey = trim($version);

        if (!in_array($editionKey, self::SUPPORTED_EDITIONS, true)) {
            return null;
        }
        if ($versionKey === '') {
            return null;
        }

        $cacheKey = $editionKey . '-' . $versionKey;
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey] ?: null;
        }

        $path = $this->manifestPath($editionKey, $versionKey);
        if (!is_file($path) || !is_readable($path)) {
            $this->cache[$cacheKey] = false;
            return null;
        }

        $manifest = $this->loadFromFile($path, $editionKey, $versionKey);
        $this->cache[$cacheKey] = $manifest ?? false;

        return $manifest;
    }

    /**
     * Build the absolute path to a manifest file.
     */
    private function manifestPath(string $edition, string $version): string
    {
        $dir = $this->manifestDir ?? dirname(__DIR__, 2) . '/etc/manifests';
        // Defence-in-depth: version strings come from ProductMetadataInterface
        // (trusted), but normalise away any path separators in case a future
        // caller plumbs an untrusted value through here.
        $safeEdition = preg_replace('/[^a-z0-9_-]/', '', $edition) ?? '';
        $safeVersion = preg_replace('/[^a-z0-9._-]/', '', $version) ?? '';

        return rtrim($dir, '/\\')
            . DIRECTORY_SEPARATOR
            . sprintf('composer-sha1-%s-%s.json', $safeEdition, $safeVersion);
    }

    private function loadFromFile(string $path, string $edition, string $version): ?ComposerLockManifest
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException(sprintf('Manifest %s is not valid JSON', basename($path)));
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Manifest %s did not decode to an object', basename($path)));
        }

        $schema = $decoded['schema_version'] ?? null;
        if ($schema !== self::SCHEMA_VERSION) {
            throw new RuntimeException(sprintf(
                'Manifest %s has schema_version=%s; expected %s',
                basename($path),
                is_scalar($schema) ? (string) $schema : 'null',
                self::SCHEMA_VERSION
            ));
        }

        $algorithm = $decoded['algorithm'] ?? self::ALGORITHM;
        if ($algorithm !== self::ALGORITHM) {
            throw new RuntimeException(sprintf(
                'Manifest %s uses algorithm=%s; expected %s',
                basename($path),
                is_scalar($algorithm) ? (string) $algorithm : 'null',
                self::ALGORITHM
            ));
        }

        $entries = $decoded['entries'] ?? null;
        if (!is_array($entries)) {
            throw new RuntimeException(sprintf('Manifest %s missing entries map', basename($path)));
        }

        $sanitised = [];
        foreach ($entries as $package => $sha) {
            if (!is_string($package) || !is_string($sha) || $package === '' || $sha === '') {
                continue;
            }
            // Composer package names follow `<vendor>/<package>`. Reject
            // anything with traversal segments so a malformed manifest
            // cannot influence callers that might use the key downstream.
            if (str_contains($package, '..') || str_starts_with($package, '/') || str_contains($package, "\0")) {
                continue;
            }
            // SHA-1 hex is always 40 chars. Reject non-hex / wrong-length
            // entries quietly — a well-formed manifest never trips this.
            $shaLower = strtolower($sha);
            if (!preg_match('/^[0-9a-f]{40}$/', $shaLower)) {
                continue;
            }
            $sanitised[$package] = $shaLower;
        }

        return new ComposerLockManifest($edition, $version, $sanitised);
    }
}
