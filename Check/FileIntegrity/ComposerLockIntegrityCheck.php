<?php

/**
 * IronCart_Scan — IC-072 composer-lock integrity check.
 *
 * Composer-level companion to {@see CoreFileIntegrityCheck} (IC-070). For
 * every package recorded in the merchant's `composer.lock`, compares
 * `dist.shasum` (the SHA-1 the package was downloaded against) to the
 * value recorded in a per-version reference manifest produced from a clean
 * `composer create-project magento/project-community-edition:<version>`.
 *
 * **No reference manifest ships with the module yet**
 * (IronCartLabs/IronCartM2#236). Until one does, this check compares nothing
 * on any install: it returns a single IC-073 finding that says composer
 * packages were not checked. That finding is a coverage gap, never a pass.
 *
 * A mismatch means the on-disk `vendor/` tree was installed from a
 * tampered or impersonated source package — the realistic Magecart vector
 * we don't catch with IC-001/IC-002 (which only check version against
 * advisory ranges) or IC-070 (which only covers the `magento/magento2`
 * source tree, not `vendor/`).
 *
 * Findings:
 *
 *   - `IC-072` HIGH — `dist.shasum` differs from the reference manifest for
 *                     a known-magento package
 *   - `IC-073` LOW  — not checked: no manifest for this (edition, version).
 *                     `evidence.reason` is `no_manifests_bundled` (the module
 *                     carries none at all), `unsupported_edition` or
 *                     `unsupported_version`; `evidence.checked` is `false`
 *
 * `IC-073` is also the ID of the unrelated Recon baseline-drift finding in
 * {@see \IronCart\Scan\Check\Integrity\FileHashCheck}; splitting the two is
 * tracked in IronCartLabs/IronCartM2#239.
 *
 * Packages NOT in the reference manifest are silently ignored (third-party
 * marketplace modules — we have no public oracle for those at v2).
 *
 * Read-only. Makes no outbound network calls.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\FileIntegrity;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\ProductMetadataInterface;
use RuntimeException;

/**
 * IC-072 — Composer-lock SHA-1 integrity (vendor-tree provenance).
 */
class ComposerLockIntegrityCheck implements CheckInterface
{
    public const ID = 'IC-072';

    public const UNSUPPORTED_ID = 'IC-073';

    public const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-072';

    public const UNSUPPORTED_REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-073';

    public function __construct(
        private readonly ProductMetadataInterface $productMetadata,
        private readonly ComposerLockReader $lockReader,
        private readonly ComposerLockManifestRepository $manifestRepository
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function title(): string
    {
        return 'Composer-lock integrity';
    }

    public function run(): array
    {
        $version = (string) $this->productMetadata->getVersion();
        $edition = (string) $this->productMetadata->getEdition();

        $manifest = $this->manifestRepository->find($edition, $version);
        if ($manifest === null) {
            return [$this->unsupportedFinding($edition, $version)];
        }

        try {
            $installed = $this->lockReader->packagesWithDist();
        } catch (RuntimeException) {
            // The lockfile is unreadable — IC-002 already covers the
            // "composer.lock missing or malformed" path with its own
            // findings; we degrade silently here rather than double-report.
            return [];
        }

        $findings = [];
        foreach ($installed as $package => $details) {
            $expected = $manifest->expectedShaFor($package);
            if ($expected === null) {
                // Third-party / marketplace package — no oracle at v2.
                continue;
            }
            $actual = $details['dist_shasum'];
            if ($actual === $expected) {
                continue;
            }
            $findings[] = $this->mismatchFinding($package, $details['version'], $expected, $actual);
        }

        return $findings;
    }

    /**
     * @return array{id:string,title:string,severity:string,evidence:array<string,mixed>,remediation_url:string}
     */
    private function mismatchFinding(string $package, string $version, string $expected, string $actual): array
    {
        return [
            'id' => self::ID,
            'title' => sprintf('Tampered composer package: %s', $package),
            'severity' => Severity::HIGH,
            'evidence' => [
                'package' => $package,
                'version' => $version,
                'expected_sha1' => $expected,
                'actual_sha1' => $actual,
            ],
            'remediation_url' => self::REMEDIATION_URL,
        ];
    }

    /**
     * @return array{id:string,title:string,severity:string,evidence:array<string,mixed>,remediation_url:string}
     */
    private function unsupportedFinding(string $edition, string $version): array
    {
        $bundledVersions = $this->manifestRepository->bundledVersions();
        $supportedEditions = ComposerLockManifestRepository::SUPPORTED_EDITIONS;
        if (array_filter($bundledVersions) === []) {
            $reason = 'no_manifests_bundled';
        } elseif (!in_array(strtolower(trim($edition)), $supportedEditions, true)) {
            $reason = 'unsupported_edition';
        } else {
            $reason = 'unsupported_version';
        }

        return [
            'id' => self::UNSUPPORTED_ID,
            'title' => sprintf(
                'Composer package integrity not checked: no reference manifest for Magento %s',
                $version !== '' ? $version : '(unknown version)'
            ),
            'severity' => Severity::LOW,
            'evidence' => [
                'edition' => $edition,
                'version' => $version,
                'reason' => $reason,
                'checked' => false,
                'supported_editions' => $supportedEditions,
                'bundled_versions' => $bundledVersions,
                'note' => 'IC-072 did not run: no composer.lock dist.shasum was compared against a reference '
                    . 'value. This is a coverage gap, not a clean result.',
            ],
            'remediation_url' => self::UNSUPPORTED_REMEDIATION_URL,
        ];
    }
}
