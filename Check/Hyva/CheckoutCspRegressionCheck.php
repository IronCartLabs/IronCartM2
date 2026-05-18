<?php

/**
 * IronCart_Scan — IC-911: Hyvä checkout CSP whitelist hash drift.
 *
 * Hyvä Checkout (the `hyva-themes/magento2-hyva-checkout` package) ships
 * an inline-JS bootstrap that the merchant's CSP whitelist must allow
 * via `script-src` hashes. When the merchant upgrades the package, the
 * shipped bootstrap is regenerated and its hash rotates — but the
 * merchant's `etc/csp_whitelist.xml` often still names the *previous*
 * version's hashes. Two failure modes follow:
 *
 *   1. The checkout breaks silently in strict CSP mode (the new bootstrap
 *      is blocked because its hash isn't whitelisted).
 *   2. The merchant pastes the new hash but leaves the old one in
 *      place "just in case" — creating a stale `script-src 'sha256-...'`
 *      entry that no longer corresponds to any shipped vendor file. A
 *      stale hash is dead weight in v4.4, but is a latent vector if an
 *      attacker can introduce content whose hash matches a stale entry.
 *
 * The check reads the merchant's project-level `app/etc/csp_whitelist.xml`
 * (and the module-level fallbacks under `vendor/hyva-themes/*` if
 * present), extracts every `<value type="hash" algorithm="sha256">…</value>`
 * entry under `script-src`, and compares against the bundled manifest at
 * `etc/manifests/hyva-checkout/<version>.json`. Any whitelisted hash that
 * does NOT appear in the manifest for the installed version is flagged
 * as stale.
 *
 * No network calls. The manifest ships in-repo; refresh path is the same
 * `bin/generate-hyva-manifest.php` flow as IC-070/IC-072. If the bundled
 * manifest doesn't cover the installed version (operator on a brand-new
 * Hyvä release), a single LOW informational finding tells them where to
 * file a manifest-update ticket — no false-positive HIGH spam.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Hyva;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\Finding;
use IronCart\Scan\Report\Severity;
use JsonException;

/**
 * IC-911 — Hyvä Checkout CSP hash whitelist drift.
 */
class CheckoutCspRegressionCheck implements CheckInterface
{
    public const ID = 'IC-911';

    public const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-911';

    public const CHECKOUT_PACKAGE = 'hyva-themes/magento2-hyva-checkout';

    private const MANIFEST_SUBDIR = 'etc'
        . DIRECTORY_SEPARATOR . 'manifests'
        . DIRECTORY_SEPARATOR . 'hyva-checkout';

    public function __construct(
        private readonly HyvaDetector $detector,
        private readonly ?string $magentoRoot = null,
        private readonly ?string $manifestDir = null
    ) {
    }

    public function run(): array
    {
        if (!$this->detector->isDetected()) {
            return [];
        }

        $packages = $this->detector->hyvaPackages();
        $checkoutVersion = $packages[self::CHECKOUT_PACKAGE] ?? null;
        if ($checkoutVersion === null) {
            // Hyvä storefront present but Hyvä Checkout not installed —
            // no CSP regression surface to evaluate.
            return [];
        }

        $whitelistPath = $this->locateWhitelist();
        if ($whitelistPath === null) {
            return [];
        }

        $whitelistHashes = $this->extractScriptSrcHashes($whitelistPath);
        if ($whitelistHashes === []) {
            return [];
        }

        $manifest = $this->loadManifest($checkoutVersion);
        if ($manifest === null) {
            return [
                Finding::make(
                    id: self::ID,
                    title: 'Hyvä Checkout CSP manifest missing for installed version',
                    severity: Severity::LOW,
                    evidence: [
                        'status' => 'manifest_unavailable',
                        'installed_version' => $checkoutVersion,
                        'whitelist_path' => $whitelistPath,
                    ],
                    remediationUrl: self::REMEDIATION_URL
                ),
            ];
        }

        $expected = array_fill_keys($manifest['hashes'] ?? [], true);
        $stale = [];
        foreach ($whitelistHashes as $hash) {
            if (!isset($expected[$hash])) {
                $stale[] = $hash;
            }
        }

        if ($stale === []) {
            return [];
        }

        return [
            Finding::make(
                id: self::ID,
                title: sprintf(
                    '%d stale Hyvä Checkout hash(es) in CSP whitelist',
                    count($stale)
                ),
                severity: Severity::MEDIUM,
                evidence: [
                    'installed_version' => $checkoutVersion,
                    'whitelist_path' => $whitelistPath,
                    'stale_hashes' => $stale,
                    'expected_count' => count($manifest['hashes'] ?? []),
                ],
                remediationUrl: self::REMEDIATION_URL
            ),
        ];
    }

    /**
     * Pull every `sha256` hash under any `<policy id="script-src">` from
     * the merchant's CSP whitelist XML. We intentionally do NOT parse
     * the full Magento schema — we only want the hash values; anything
     * else (host allowlists, nonces) is out of scope for IC-911.
     *
     * @return list<string>
     */
    private function extractScriptSrcHashes(string $path): array
    {
        $xml = @simplexml_load_file($path);
        if ($xml === false) {
            return [];
        }

        $hashes = [];
        // The whitelist schema nests <policies><policy
        // id="script-src"><values><value
        // type="hash" algorithm="sha256">...</value></values></policy>.
        // We accept both `algorithm="sha256"` and an unspecified
        // algorithm attribute, as some merchant XMLs omit it.
        foreach ($xml->xpath('//policy[@id="script-src"]//value[@type="hash"]') ?: [] as $node) {
            $algo = (string) ($node['algorithm'] ?? '');
            if ($algo !== '' && strtolower($algo) !== 'sha256') {
                continue;
            }
            $value = trim((string) $node);
            if ($value !== '') {
                $hashes[] = $value;
            }
        }

        return array_values(array_unique($hashes));
    }

    /**
     * Load the bundled manifest for `$version`. Returns null if the
     * manifest is missing or malformed — the caller surfaces that as
     * a LOW informational finding rather than a false-positive
     * MEDIUM stale-hash spam.
     *
     * @return array{version:string, hashes:list<string>}|null
     */
    private function loadManifest(string $version): ?array
    {
        $dir = $this->resolveManifestDir();
        if ($dir === null) {
            return null;
        }
        $candidate = $dir . DIRECTORY_SEPARATOR . $version . '.json';
        if (!is_file($candidate)) {
            return null;
        }
        $body = @file_get_contents($candidate);
        if ($body === false) {
            return null;
        }
        try {
            $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        $hashes = $decoded['hashes'] ?? null;
        if (!is_array($hashes)) {
            return null;
        }
        $clean = [];
        foreach ($hashes as $hash) {
            if (is_string($hash) && $hash !== '') {
                $clean[] = $hash;
            }
        }
        return ['version' => $version, 'hashes' => $clean];
    }

    private function resolveManifestDir(): ?string
    {
        if ($this->manifestDir !== null) {
            return is_dir($this->manifestDir) ? $this->manifestDir : null;
        }
        // The manifest ships inside the module (`<module>/etc/manifests/hyva-checkout/`).
        $candidate = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::MANIFEST_SUBDIR;
        return is_dir($candidate) ? $candidate : null;
    }

    /**
     * Find the merchant's CSP whitelist. Magento convention is
     * `app/etc/csp_whitelist.xml` (project-level); we don't walk
     * module-level whitelists because IC-911's contract is "what is
     * the deployed surface admitting", which is the project copy.
     */
    private function locateWhitelist(): ?string
    {
        $root = $this->resolveMagentoRoot();
        if ($root === null) {
            return null;
        }
        $candidate = $root
            . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'etc'
            . DIRECTORY_SEPARATOR . 'csp_whitelist.xml';
        return is_file($candidate) ? $candidate : null;
    }

    private function resolveMagentoRoot(): ?string
    {
        if ($this->magentoRoot !== null) {
            return is_dir($this->magentoRoot) ? $this->magentoRoot : null;
        }
        $dir = __DIR__;
        for ($i = 0; $i < 10; $i++) {
            if (is_file($dir . DIRECTORY_SEPARATOR . 'composer.lock')) {
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
