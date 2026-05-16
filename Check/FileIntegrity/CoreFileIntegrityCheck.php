<?php

/**
 * IronCart_Scan — IC-070 core-file-integrity check.
 *
 * Compares the live SHA-256 of every file in a bundled manifest against the
 * expected hash, and emits one HIGH-severity finding per mismatch.
 *
 * The manifest is derived from the public `magento/magento2` git tag for the
 * merchant's reported version. **There is no Adobe-published file-hash
 * manifest** — the previous design premise turned out not to be implementable
 * (see IronCartLabs/IronCartM2#47 for the decision and the scope reduction to
 * Magento Open Source only). Manifests live under `etc/manifests/` and are
 * built via `make manifests`; this check only ever reads from disk.
 *
 * Findings:
 *
 *   - `IC-070` HIGH — `evidence.kind = "mismatch"`: file present, SHA differs from manifest
 *   - `IC-070` HIGH — `evidence.kind = "missing"`: manifest entry not on disk
 *   - `IC-070` HIGH — `evidence.kind = "mass_tampering"`: > 200 altered files (summary)
 *   - `IC-071` LOW  — manifest not available for this (edition, version)
 *
 * Adobe Commerce merchants always receive IC-071 in v2 — manifest coverage
 * for AC is deferred to v3 hosted backend. Extra files in the webroot that
 * are not in the manifest are intentionally NOT reported (marketplace
 * modules legitimately add files to `pub/static/frontend/...`).
 *
 * Read-only. Makes no outbound network calls.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\FileIntegrity;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\Filesystem\MagentoRoot;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\ProductMetadataInterface;

/**
 * IC-070 — Core file integrity (file-level SHA-256 against bundled manifest).
 */
class CoreFileIntegrityCheck implements CheckInterface
{
    public const ID = 'IC-070';

    public const UNSUPPORTED_ID = 'IC-071';

    public const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-070';

    public const UNSUPPORTED_REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-071';

    public const MASS_TAMPERING_REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-070-mass-tampering';

    /**
     * Findings emitted before we switch to a single summary finding. Per the
     * issue body — > 200 altered files is almost always a compromised webroot,
     * and a 200-line scan report is already past the operator's attention span.
     */
    public const MAX_DETAILED_FINDINGS = 200;

    /**
     * Manifest-relative path prefixes (or exact files) that should never be
     * hashed against the manifest:
     *
     *   - `var/`, `generated/`           — generated at runtime
     *   - `pub/static/`, `pub/media/`    — deploy / merchant artefacts
     *   - `app/etc/env.php`              — per-install secrets
     *   - `app/etc/config.php`           — per-install module + store config
     *   - `.git/`                        — never in a clean install
     *
     * The first three should never appear in a manifest built from
     * `magento/magento2` source either, but we walk them out defensively in
     * case a future generator drift includes them.
     *
     * @var list<string>
     */
    private const IGNORED_PREFIXES = [
        'var/',
        'generated/',
        'pub/static/',
        'pub/media/',
        '.git/',
    ];

    /**
     * @var list<string>
     */
    private const IGNORED_EXACT = [
        'app/etc/env.php',
        'app/etc/config.php',
    ];

    public function __construct(
        private readonly ProductMetadataInterface $productMetadata,
        private readonly MagentoRoot $root,
        private readonly ManifestRepository $manifestRepository
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function title(): string
    {
        return 'Core file integrity';
    }

    public function run(): array
    {
        $version = (string) $this->productMetadata->getVersion();
        $edition = (string) $this->productMetadata->getEdition();

        $manifest = $this->manifestRepository->find($edition, $version);
        if ($manifest === null) {
            return [$this->unsupportedFinding($edition, $version)];
        }

        $algorithm = $manifest->algorithm();
        $findings = [];
        $mismatchCount = 0;
        $missingCount = 0;
        $checkedCount = 0;
        $ignoredCount = 0;

        foreach ($manifest->entries() as $relative => $expectedHash) {
            if ($this->isIgnored($relative)) {
                $ignoredCount++;
                continue;
            }

            $absolute = $this->root->join($relative);
            $checkedCount++;

            if (!is_file($absolute)) {
                if (count($findings) < self::MAX_DETAILED_FINDINGS) {
                    $findings[] = $this->missingFinding($relative, $expectedHash);
                }
                $missingCount++;
                continue;
            }

            $actualHash = @hash_file($algorithm, $absolute);
            if ($actualHash === false) {
                // Unreadable — treat as missing rather than silently dropping.
                if (count($findings) < self::MAX_DETAILED_FINDINGS) {
                    $findings[] = $this->missingFinding($relative, $expectedHash);
                }
                $missingCount++;
                continue;
            }

            if (strtolower($actualHash) === $expectedHash) {
                continue;
            }

            if (count($findings) < self::MAX_DETAILED_FINDINGS) {
                $findings[] = $this->mismatchFinding(
                    $relative,
                    $absolute,
                    $expectedHash,
                    strtolower($actualHash),
                    @filesize($absolute) ?: null
                );
            }
            $mismatchCount++;
        }

        $totalAltered = $mismatchCount + $missingCount;
        if ($totalAltered > self::MAX_DETAILED_FINDINGS) {
            $findings[] = $this->massTamperingFinding(
                $totalAltered,
                $mismatchCount,
                $missingCount,
                $checkedCount,
                $manifest
            );
        } elseif ($findings !== []) {
            // Below the mass-tampering threshold — append a summary info
            // finding so the report includes coverage telemetry alongside
            // the per-file detail.
            $findings[] = $this->coverageSummaryFinding(
                $totalAltered,
                $mismatchCount,
                $missingCount,
                $checkedCount,
                $ignoredCount,
                $manifest
            );
        }

        return $findings;
    }

    /**
     * @return array{id:string,title:string,severity:string,evidence:array<string,mixed>,remediation_url:string}
     */
    private function unsupportedFinding(string $edition, string $version): array
    {
        $editionKey = strtolower(trim($edition));
        $reason = in_array($editionKey, ManifestRepository::SUPPORTED_EDITIONS, true)
            ? 'unsupported_version'
            : 'unsupported_edition';

        return [
            'id' => self::UNSUPPORTED_ID,
            'title' => sprintf(
                'File integrity manifest not available for Magento %s',
                $version !== '' ? $version : '(unknown version)'
            ),
            'severity' => Severity::LOW,
            'evidence' => [
                'edition' => $edition,
                'version' => $version,
                'reason' => $reason,
                'supported_editions' => ManifestRepository::SUPPORTED_EDITIONS,
            ],
            'remediation_url' => self::UNSUPPORTED_REMEDIATION_URL,
        ];
    }

    /**
     * @return array{id:string,title:string,severity:string,evidence:array<string,mixed>,remediation_url:string}
     */
    private function mismatchFinding(
        string $relative,
        string $absolute,
        string $expectedHash,
        string $actualHash,
        ?int $sizeBytes
    ): array {
        return [
            'id' => self::ID,
            'title' => sprintf('Altered core file: %s', $relative),
            'severity' => Severity::HIGH,
            'evidence' => [
                'kind' => 'mismatch',
                'file' => $relative,
                'absolute_path' => $absolute,
                'expected_sha' => $expectedHash,
                'actual_sha' => $actualHash,
                'size_bytes' => $sizeBytes,
            ],
            'remediation_url' => self::REMEDIATION_URL,
        ];
    }

    /**
     * @return array{id:string,title:string,severity:string,evidence:array<string,mixed>,remediation_url:string}
     */
    private function missingFinding(string $relative, string $expectedHash): array
    {
        return [
            'id' => self::ID,
            'title' => sprintf('Missing core file: %s', $relative),
            'severity' => Severity::HIGH,
            'evidence' => [
                'kind' => 'missing',
                'file' => $relative,
                'expected_sha' => $expectedHash,
            ],
            'remediation_url' => self::REMEDIATION_URL,
        ];
    }

    /**
     * @return array{id:string,title:string,severity:string,evidence:array<string,mixed>,remediation_url:string}
     */
    private function massTamperingFinding(
        int $totalAltered,
        int $mismatchCount,
        int $missingCount,
        int $checkedCount,
        Manifest $manifest
    ): array {
        return [
            'id' => self::ID,
            'title' => sprintf('More than %d altered core files — likely compromised webroot', self::MAX_DETAILED_FINDINGS),
            'severity' => Severity::CRITICAL,
            'evidence' => [
                'kind' => 'mass_tampering',
                'altered_files_total' => $totalAltered,
                'mismatch_count' => $mismatchCount,
                'missing_count' => $missingCount,
                'checked_files' => $checkedCount,
                'manifest_edition' => $manifest->edition(),
                'manifest_version' => $manifest->version(),
                'detailed_findings_truncated_at' => self::MAX_DETAILED_FINDINGS,
            ],
            'remediation_url' => self::MASS_TAMPERING_REMEDIATION_URL,
        ];
    }

    /**
     * @return array{id:string,title:string,severity:string,evidence:array<string,mixed>,remediation_url:string}
     */
    private function coverageSummaryFinding(
        int $totalAltered,
        int $mismatchCount,
        int $missingCount,
        int $checkedCount,
        int $ignoredCount,
        Manifest $manifest
    ): array {
        return [
            'id' => self::ID,
            'title' => 'Core file integrity scan complete',
            'severity' => Severity::INFO,
            'evidence' => [
                'kind' => 'summary',
                'altered_files_total' => $totalAltered,
                'mismatch_count' => $mismatchCount,
                'missing_count' => $missingCount,
                'checked_files' => $checkedCount,
                'ignored_entries' => $ignoredCount,
                'manifest_edition' => $manifest->edition(),
                'manifest_version' => $manifest->version(),
            ],
            'remediation_url' => self::REMEDIATION_URL,
        ];
    }

    private function isIgnored(string $relative): bool
    {
        if (in_array($relative, self::IGNORED_EXACT, true)) {
            return true;
        }
        foreach (self::IGNORED_PREFIXES as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
