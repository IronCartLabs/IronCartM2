<?php

/**
 * IronCart_Scan — Recon 7.1 baseline persistence.
 *
 * Loads + saves the Recon file-integrity baseline at the conventional
 * `var/recon/baseline.json` location beneath the Magento project root. The
 * file is the local source of truth for the diff; uploading it to ironcart.dev
 * is handled separately by the existing scan-ingest pipeline.
 *
 * The on-disk format is the canonical JSON shape documented on
 * {@see BaselineManifest::toJson()}. Reads tolerate the file being absent —
 * {@see FileHashCheck} surfaces a LOW informational finding in that case
 * (pointing the operator at `bin/magento recon:integrity:rebaseline`).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Integrity;

use IronCart\Scan\Check\Filesystem\MagentoRoot;
use IronCart\Scan\Check\Manifests\ManifestEntrySanitiser;
use RuntimeException;

class BaselineRepository
{
    public const BASELINE_RELATIVE_PATH = 'var/recon/baseline.json';

    public function __construct(private readonly MagentoRoot $magentoRoot)
    {
    }

    /**
     * Absolute path the baseline is read from / written to.
     */
    public function path(): string
    {
        return $this->magentoRoot->join(self::BASELINE_RELATIVE_PATH);
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * Load the baseline from disk, or `null` when no baseline has been
     * generated yet (i.e. the check has never been rebaselined on this
     * install). Throws on schema drift / malformed JSON — corrupted
     * baselines must never be silently treated as "everything is fine".
     */
    public function load(): ?BaselineManifest
    {
        $path = $this->path();
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException(sprintf('Baseline file %s is not valid JSON', $path));
        }
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Baseline file %s did not decode to an object', $path));
        }
        $schema = $decoded['schema_version'] ?? null;
        if ($schema !== BaselineManifest::SCHEMA_VERSION) {
            throw new RuntimeException(sprintf(
                'Baseline file %s has schema_version=%s; expected %s',
                $path,
                is_scalar($schema) ? (string) $schema : 'null',
                BaselineManifest::SCHEMA_VERSION
            ));
        }
        $algorithm = is_string($decoded['algorithm'] ?? null) && $decoded['algorithm'] !== ''
            ? (string) $decoded['algorithm']
            : BaselineManifest::ALGORITHM_SHA256;

        $entries = $decoded['entries'] ?? null;
        if (!is_array($entries)) {
            throw new RuntimeException(sprintf('Baseline file %s missing entries map', $path));
        }
        $sanitised = ManifestEntrySanitiser::sanitise($entries);

        $roots = [];
        if (is_array($decoded['roots'] ?? null)) {
            foreach ($decoded['roots'] as $root) {
                if (is_string($root) && $root !== '') {
                    $roots[] = $root;
                }
            }
        }

        return new BaselineManifest(
            generatedAt: is_string($decoded['generated_at'] ?? null) ? (string) $decoded['generated_at'] : '',
            magentoEdition: is_string($decoded['magento_edition'] ?? null) ? (string) $decoded['magento_edition'] : '',
            magentoVersion: is_string($decoded['magento_version'] ?? null) ? (string) $decoded['magento_version'] : '',
            algorithm: $algorithm,
            roots: $roots,
            entries: $sanitised
        );
    }

    /**
     * Persist a baseline. Creates the parent directory if missing. The file
     * is written with mode 0640 so the merchant's webserver user can read it
     * back on the next scan but it isn't world-readable (the baseline
     * indirectly lists every shipped PHP file, which is fine to leak but no
     * reason to leak gratuitously).
     */
    public function save(BaselineManifest $manifest): void
    {
        $path = $this->path();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Unable to create baseline directory "%s"', $dir));
        }

        $json = $manifest->toJson();
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write baseline to "%s"', $path));
        }
        @chmod($path, 0o640);
    }
}
