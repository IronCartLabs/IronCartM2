<?php

/**
 * IronCart_Scan — IC-072 composer-lock integrity check.
 *
 * Composer-level companion to {@see CoreFileIntegrityCheck} (IC-070). For
 * every package recorded in the merchant's `composer.lock`, compares
 * `dist.shasum` (the SHA-1 the package was downloaded against) to the
 * value recorded in a bundled per-version reference manifest produced from
 * a clean `composer create-project magento/project-community-edition:<version>`.
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
 *   - `IC-073` LOW  — manifest not available for this (edition, version)
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
        $editionKey = strtolower(trim($edition));
        $reason = in_array($editionKey, ComposerLockManifestRepository::SUPPORTED_EDITIONS, true)
            ? 'unsupported_version'
            : 'unsupported_edition';

        return [
            'id' => self::UNSUPPORTED_ID,
            'title' => sprintf(
                'Composer integrity manifest not available for Magento %s',
                $version !== '' ? $version : '(unknown version)'
            ),
            'severity' => Severity::LOW,
            'evidence' => [
                'edition' => $edition,
                'version' => $version,
                'reason' => $reason,
                'supported_editions' => ComposerLockManifestRepository::SUPPORTED_EDITIONS,
            ],
            'remediation_url' => self::UNSUPPORTED_REMEDIATION_URL,
        ];
    }
}
