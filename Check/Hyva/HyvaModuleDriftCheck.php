<?php

/**
 * IronCart_Scan — IC-912: Hyvä module version drift.
 *
 * Flags `hyva-themes/magento2-*` composer packages installed below the
 * latest security-tagged release for that package. Reads strictly from
 * the bundled `etc/manifests/hyva-modules/min-versions.json` file —
 * no network call, no Hyvä-license probing, no Packagist hit. The
 * manifest is refreshed on the same schedule as the OSV snapshot
 * (`bin/refresh-osv-snapshot.php`) so version-floor data lives next
 * to the existing IC-002 cross-reference path.
 *
 * For each installed Hyvä package we have a min-version row for,
 * `version_compare()` against the installed version decides whether
 * the row is drift. Packages we have no manifest entry for are
 * silently skipped — IC-912's contract is "I know this package needs
 * to be ≥ X; you have < X"; for everything else IC-060/IC-002 already
 * provide CVE-driven coverage.
 *
 * Severity is `medium` by default; promoted to `high` when the
 * manifest tags the row with `"security": true` (i.e. the floor was
 * set because of a published advisory rather than a routine bugfix).
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
 * IC-912 — Hyvä module version drift against the bundled min-version manifest.
 */
class HyvaModuleDriftCheck implements CheckInterface
{
    public const ID = 'IC-912';

    public const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-912';

    public const MANIFEST_FILENAME = 'min-versions.json';

    private const MANIFEST_SUBDIR = 'etc'
        . DIRECTORY_SEPARATOR . 'manifests'
        . DIRECTORY_SEPARATOR . 'hyva-modules';

    public function __construct(
        private readonly HyvaDetector $detector,
        private readonly ?string $manifestDir = null
    ) {
    }

    public function run(): array
    {
        if (!$this->detector->isDetected()) {
            return [];
        }

        $packages = $this->detector->hyvaPackages();
        if ($packages === []) {
            return [];
        }

        $manifest = $this->loadManifest();
        if ($manifest === []) {
            return [];
        }

        $findings = [];
        foreach ($packages as $name => $installedVersion) {
            $row = $manifest[$name] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $minVersion = isset($row['min_version']) && is_string($row['min_version'])
                ? $row['min_version']
                : null;
            if ($minVersion === null || $minVersion === '') {
                continue;
            }
            if (version_compare($installedVersion, $minVersion, '>=')) {
                continue;
            }

            $isSecurity = !empty($row['security']);
            $severity = $isSecurity ? Severity::HIGH : Severity::MEDIUM;

            $findings[] = Finding::make(
                id: self::ID,
                title: sprintf('%s — installed %s < %s', $name, $installedVersion, $minVersion),
                severity: $severity,
                evidence: [
                    'package' => $name,
                    'installed_version' => $installedVersion,
                    'min_version' => $minVersion,
                    'security' => $isSecurity,
                    'note' => isset($row['note']) && is_string($row['note']) ? $row['note'] : '',
                ],
                remediationUrl: self::REMEDIATION_URL
            );
        }

        return $findings;
    }

    /**
     * Load the bundled `min-versions.json` manifest. Returns an empty
     * array when the file is missing or malformed — IC-912 silently
     * degrades to "nothing to check" rather than emitting a false-
     * positive finding.
     *
     * @return array<string,array<string,mixed>>
     */
    private function loadManifest(): array
    {
        $dir = $this->resolveManifestDir();
        if ($dir === null) {
            return [];
        }
        $path = $dir . DIRECTORY_SEPARATOR . self::MANIFEST_FILENAME;
        if (!is_file($path)) {
            return [];
        }
        $body = @file_get_contents($path);
        if ($body === false) {
            return [];
        }
        try {
            $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        $packages = $decoded['packages'] ?? null;
        if (!is_array($packages)) {
            return [];
        }
        $out = [];
        foreach ($packages as $name => $row) {
            if (is_string($name) && $name !== '' && is_array($row)) {
                $out[$name] = $row;
            }
        }
        return $out;
    }

    private function resolveManifestDir(): ?string
    {
        if ($this->manifestDir !== null) {
            return is_dir($this->manifestDir) ? $this->manifestDir : null;
        }
        $candidate = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::MANIFEST_SUBDIR;
        return is_dir($candidate) ? $candidate : null;
    }
}
