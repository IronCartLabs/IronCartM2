<?php

/**
 * IronCart_Scan — upload payload builder.
 *
 * Assembles the JSON document the `--upload` flow POSTs to the IronCartWeb
 * ingest endpoint. The shape is defined by the ingest contract in
 * IronCartLabs/IronCartWeb#984 (schema_version "1"):
 *
 *   {
 *     "schema_version": "1",
 *     "source": "ironcart-magento-scan/<module_version>",
 *     "license_blob": "...",  // OPTIONAL — present when a Pro license
 *                              //            is configured and verifies
 *                              //            (#103)
 *     "store": {
 *       "base_url": "...",
 *       "magento_version": "...",
 *       "magento_edition": "...",
 *       "module_version": "...",
 *       "composer_packages": [{"name": "...", "version": "..."}]
 *     },
 *     "findings": [
 *       {"check_id": "...", "severity": "...", "title": "...",
 *        "evidence": {...}, "remediation_url": "..."}
 *     ]
 *   }
 *
 * Hard invariants enforced here:
 *
 *   - The output array has NO `admin_email`, NO `operator_email`, NO
 *     `admin_username` keys at any nesting depth. A unit test asserts this
 *     by recursively walking the serialised JSON.
 *   - `composer_packages.length > 1000` OR `findings.length > 500` →
 *     {@see PayloadTooLargeException}. The bounds mirror the server's
 *     413 cutoffs.
 *   - `license_blob` is OMITTED when no license is configured OR when
 *     the configured blob fails verification. Forwarding a corrupted /
 *     expired blob to the server is never useful — the hosted backend
 *     would just reject it — and would leak the malformed string into
 *     server-side logs.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Upload;

use IronCart\Scan\Check\License\LicenseConfig;
use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Store\Model\ScopeInterface;
use Throwable;

/**
 * Assembles the ingest payload from existing scan output + Magento metadata.
 */
class UploadPayloadBuilder
{
    /**
     * Schema version sent with every payload. Bumping this requires a
     * coordinated change on the IronCartWeb ingest side; mismatches are
     * rejected server-side with a 422.
     */
    public const SCHEMA_VERSION = '1';

    /**
     * Hard server-side cutoff on findings. The IronCartWeb ingest endpoint
     * returns 413 above this; we short-circuit module-side so the operator
     * sees a clear "payload too large" message instead of a generic 413.
     */
    public const MAX_FINDINGS = 500;

    /**
     * Hard server-side cutoff on composer packages.
     */
    public const MAX_COMPOSER_PACKAGES = 1000;

    private const CONFIG_BASE_URL = 'web/secure/base_url';
    private const CONFIG_FALLBACK_BASE_URL = 'web/unsecure/base_url';

    public function __construct(
        private readonly ProductMetadataInterface $productMetadata,
        private readonly ComposerLockReader $composerLockReader,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly string $moduleVersion,
        private readonly ?LicenseConfig $licenseConfig = null
    ) {
    }

    /**
     * Build the ingest payload.
     *
     * @param list<array{
     *     id:string,
     *     title:string,
     *     severity:string,
     *     evidence:mixed,
     *     remediation_url:string
     * }> $findings Findings produced by {@see \IronCart\Scan\Check\CheckRegistry::runAll()}.
     *
     * @throws PayloadTooLargeException When the payload would exceed the server's 413 cutoff.
     *
     * @return array{
     *     schema_version:string,
     *     source:string,
     *     store:array{
     *         base_url:string,
     *         magento_version:string,
     *         magento_edition:string,
     *         module_version:string,
     *         composer_packages:list<array{name:string,version:string}>
     *     },
     *     findings:list<array{
     *         check_id:string,
     *         severity:string,
     *         title:string,
     *         evidence:mixed,
     *         remediation_url:string
     *     }>,
     *     license_blob?:string
     * }
     */
    public function build(array $findings): array
    {
        if (count($findings) > self::MAX_FINDINGS) {
            throw new PayloadTooLargeException(sprintf(
                'Refusing to upload: %d findings exceeds the %d-finding ingest limit.',
                count($findings),
                self::MAX_FINDINGS
            ));
        }

        $packages = $this->collectComposerPackages();
        if (count($packages) > self::MAX_COMPOSER_PACKAGES) {
            throw new PayloadTooLargeException(sprintf(
                'Refusing to upload: %d composer packages exceeds the %d-package ingest limit.',
                count($packages),
                self::MAX_COMPOSER_PACKAGES
            ));
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'source' => 'ironcart-magento-scan/' . $this->moduleVersion,
            'store' => [
                'base_url' => $this->normaliseBaseUrl($this->resolveBaseUrl()),
                'magento_version' => (string) $this->productMetadata->getVersion(),
                'magento_edition' => strtolower((string) $this->productMetadata->getEdition()),
                'module_version' => $this->moduleVersion,
                'composer_packages' => $packages,
            ],
            'findings' => $this->reshapeFindings($findings),
        ];

        // #103 — only forward a license blob when the merchant has one
        // configured AND it verifies cleanly against the compiled-in
        // public key. The module never makes a license-state decision
        // on its own (all 43 checks always run regardless of license);
        // we just hand the blob to the hosted backend so it can flag
        // the resulting `scan_run.tier='pro'`.
        if ($this->licenseConfig !== null) {
            $blob = $this->licenseConfig->verifiedBlob();
            if (is_string($blob) && $blob !== '') {
                $payload['license_blob'] = $blob;
            }
        }

        return $payload;
    }

    /**
     * Pull the composer package list via the shared reader. Falls back
     * to an empty list if the lockfile is missing — uploads are still
     * useful even when running against a non-Composer Magento install,
     * the server just won't be able to cross-reference packages.
     *
     * @return list<array{name:string,version:string}>
     */
    private function collectComposerPackages(): array
    {
        try {
            $map = $this->composerLockReader->packages();
        } catch (Throwable) {
            return [];
        }

        $packages = [];
        foreach ($map as $name => $version) {
            $packages[] = ['name' => $name, 'version' => $version];
        }

        return $packages;
    }

    /**
     * Translate a v0 finding shape (`id` / `title` / `severity` / ...) into
     * the ingest contract shape (`check_id` / ...). The server schema uses
     * `check_id` to leave room for non-check-derived findings in future
     * schema versions (e.g. CSP probe rollups).
     *
     * @param list<array{
     *     id:string,
     *     title:string,
     *     severity:string,
     *     evidence:mixed,
     *     remediation_url:string
     * }> $findings
     *
     * @return list<array{
     *     check_id:string,
     *     severity:string,
     *     title:string,
     *     evidence:mixed,
     *     remediation_url:string
     * }>
     */
    private function reshapeFindings(array $findings): array
    {
        $out = [];
        foreach ($findings as $finding) {
            $out[] = [
                'check_id' => (string) ($finding['id'] ?? ''),
                'severity' => (string) ($finding['severity'] ?? ''),
                'title' => (string) ($finding['title'] ?? ''),
                'evidence' => $finding['evidence'] ?? null,
                'remediation_url' => (string) ($finding['remediation_url'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Prefer the secure base URL; fall back to the unsecure one if the
     * store hasn't configured HTTPS yet.
     */
    private function resolveBaseUrl(): string
    {
        $secure = $this->scopeConfig->getValue(self::CONFIG_BASE_URL, ScopeInterface::SCOPE_STORE);
        if (is_string($secure) && $secure !== '') {
            return $secure;
        }
        $unsecure = $this->scopeConfig->getValue(self::CONFIG_FALLBACK_BASE_URL, ScopeInterface::SCOPE_STORE);
        return is_string($unsecure) ? $unsecure : '';
    }

    /**
     * Normalise to lowercase host + no trailing slash. The server
     * disambiguates uploads by `(account_id, base_url)`, so a stable
     * canonical form is required to avoid creating phantom "second stores"
     * when the operator capitalises the host differently.
     */
    private function normaliseBaseUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || !is_string($parts['host'])) {
            return rtrim(strtolower($url), '/');
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = isset($parts['path']) && is_string($parts['path']) ? rtrim($parts['path'], '/') : '';

        return sprintf('%s://%s%s%s', $scheme, $host, $port, $path);
    }
}
