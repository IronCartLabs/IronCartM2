<?php

/**
 * IronCart_Scan — Recon 7.1 file-integrity baseline manifest.
 *
 * In-memory representation of the Recon file-integrity baseline stored at
 * `var/recon/baseline.json`. Distinct from {@see \IronCart\Scan\Check\FileIntegrity\Manifest}
 * (which represents the bundled Magento Open Source hash manifest used by
 * IC-070): the Recon baseline is generated locally from the merchant's own
 * webroot and captures whatever is actually installed (including third-party
 * modules under `app/code/Vendor/Module`).
 *
 * The on-disk JSON schema is:
 *
 *     {
 *       "schema_version": "v0",
 *       "generated_at": "2026-05-19T11:34:00+00:00",
 *       "magento_edition": "Community",
 *       "magento_version": "2.4.7-p5",
 *       "algorithm": "sha256",
 *       "roots": ["app/code", "app/etc", "vendor/magento"],
 *       "entries": {
 *         "app/code/Vendor/Module/etc/module.xml": "abcd...ef"
 *       }
 *     }
 *
 * Immutable. Constructed either by {@see BaselineBuilder} (fresh walk) or
 * {@see BaselineRepository::load()} (rehydrated from disk).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Integrity;

/**
 * Immutable Recon file-integrity baseline.
 */
final class BaselineManifest
{
    public const SCHEMA_VERSION = 'v0';

    public const ALGORITHM_SHA256 = 'sha256';

    /**
     * @param string                $generatedAt   ISO-8601 timestamp when the baseline was built
     * @param string                $magentoEdition Reported edition at baseline time (informational)
     * @param string                $magentoVersion Reported version at baseline time (informational)
     * @param string                $algorithm     Hash algorithm — `sha256` in v0
     * @param list<string>          $roots         Webroot-relative directories the baseline walked
     * @param array<string,string>  $entries       `relative_path => lowercase hex hash`
     */
    public function __construct(
        private readonly string $generatedAt,
        private readonly string $magentoEdition,
        private readonly string $magentoVersion,
        private readonly string $algorithm,
        private readonly array $roots,
        private readonly array $entries
    ) {
    }

    public function generatedAt(): string
    {
        return $this->generatedAt;
    }

    public function magentoEdition(): string
    {
        return $this->magentoEdition;
    }

    public function magentoVersion(): string
    {
        return $this->magentoVersion;
    }

    public function algorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * @return list<string>
     */
    public function roots(): array
    {
        return $this->roots;
    }

    /**
     * @return array<string,string>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Encode to the canonical JSON shape persisted at `var/recon/baseline.json`.
     * Entries are sorted lexicographically so two baselines built from the
     * same webroot produce byte-identical JSON (important for delta uploads
     * and reproducible-test fixtures).
     */
    public function toJson(): string
    {
        $entries = $this->entries;
        ksort($entries);

        return (string) json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $this->generatedAt,
            'magento_edition' => $this->magentoEdition,
            'magento_version' => $this->magentoVersion,
            'algorithm' => $this->algorithm,
            'roots' => array_values($this->roots),
            'entries' => $entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
