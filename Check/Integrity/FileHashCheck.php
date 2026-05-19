<?php

/**
 * IronCart_Scan — Recon 7.1 file-integrity diff check (IC-073).
 *
 * The Recon-tier counterpart of IC-070 ({@see \IronCart\Scan\Check\FileIntegrity\CoreFileIntegrityCheck}).
 * Where IC-070 compares against an Ironcart-built manifest of stock Magento
 * Open Source, IC-073 compares against a **locally-built baseline** of the
 * merchant's own webroot — so it can catch tampering in third-party modules,
 * `app/code/Vendor/Module`, and the bits of `vendor/magento` that ship with
 * the installed Magento version. The baseline is built by
 * `bin/magento recon:integrity:rebaseline` at install time and re-snapshotted
 * by the operator on every legitimate code change.
 *
 * The check runs on every scheduled scan (cron + CLI) and emits one finding
 * per altered file, severity assigned by path:
 *
 *   - `IC-073` HIGH    — files under `app/code/**` or `app/etc/**`
 *                        (admin / module code, the highest-risk surface for
 *                        backdoors and injected admin users)
 *   - `IC-073` MEDIUM  — everything else under `vendor/magento/**`
 *                        (still core code, but skinning attacks tend to
 *                        target frontend templates rather than security
 *                        primitives)
 *   - `IC-074` LOW     — informational: no baseline configured yet
 *                        (the Pro entitlement is present but
 *                        `recon:integrity:rebaseline` has not been run)
 *
 * Gated on a verified Pro license claim — non-Pro stores see zero findings
 * from this check (it's part of the Recon subscription per
 * IronCartLabs/IronCartWeb#1184). Read-only on the filesystem; the only on-
 * disk write is the baseline file itself, which is touched exclusively by
 * the rebaseline command.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Integrity;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\Filesystem\MagentoRoot;
use IronCart\Scan\Check\License\LicenseConfig;
use IronCart\Scan\Report\Severity;

class FileHashCheck implements CheckInterface
{
    public const ID = 'IC-073';

    public const NO_BASELINE_ID = 'IC-074';

    public const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-073';

    public const NO_BASELINE_REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-074';

    public const MASS_TAMPERING_REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-073-mass-tampering';

    /**
     * Mirror IC-070's threshold: > 200 altered files almost always means a
     * compromised webroot, and per-file detail past that point is noise.
     */
    public const MAX_DETAILED_FINDINGS = 200;

    /**
     * Path prefixes that lift severity from MEDIUM to HIGH on a mismatch.
     * Order matters only insofar as the first match wins — keep the longer
     * prefix first if/when overlaps are introduced.
     *
     * @var list<string>
     */
    private const HIGH_SEVERITY_PREFIXES = [
        'app/code/',
        'app/etc/',
    ];

    public function __construct(
        private readonly MagentoRoot $magentoRoot,
        private readonly BaselineRepository $baselineRepository,
        private readonly IgnorePatterns $ignorePatterns,
        private readonly BaselineBuilder $baselineBuilder,
        private readonly LicenseConfig $licenseConfig
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function title(): string
    {
        return 'Recon file integrity';
    }

    public function run(): array
    {
        // Pro-only — non-Pro merchants see zero findings from this check.
        // No license / failed verification both short-circuit silently;
        // the LicenseVerifier is the source of truth for entitlement.
        if ($this->licenseConfig->parsedClaims() === null) {
            return [];
        }

        $baseline = $this->baselineRepository->load();
        if ($baseline === null) {
            return [$this->noBaselineFinding()];
        }

        $current = $this->snapshotCurrent();
        return $this->diff($baseline->entries(), $current, $baseline);
    }

    /**
     * Pure diff: given two `relative => hash` maps, classify each entry as
     * new / modified / deleted, assign severity by path, and cap the
     * detailed-finding list with a mass-tampering summary above the
     * threshold. Exposed (`public`) so unit tests can drive it directly
     * without a live filesystem.
     *
     * @param array<string,string> $baselineEntries
     * @param array<string,string> $currentEntries
     * @return list<array{id:string,title:string,severity:string,evidence:array<string,mixed>,remediation_url:string}>
     */
    public function diff(array $baselineEntries, array $currentEntries, BaselineManifest $baseline): array
    {
        $findings = [];
        $modified = 0;
        $missing = 0;
        $new = 0;

        // Modified + deleted: walk the baseline keys.
        foreach ($baselineEntries as $relative => $expectedHash) {
            if (!array_key_exists($relative, $currentEntries)) {
                if (count($findings) < self::MAX_DETAILED_FINDINGS) {
                    $findings[] = $this->finding(
                        kind: 'deleted',
                        relative: $relative,
                        expectedHash: $expectedHash,
                        actualHash: null
                    );
                }
                $missing++;
                continue;
            }
            $actualHash = $currentEntries[$relative];
            if ($actualHash === $expectedHash) {
                continue;
            }
            if (count($findings) < self::MAX_DETAILED_FINDINGS) {
                $findings[] = $this->finding(
                    kind: 'modified',
                    relative: $relative,
                    expectedHash: $expectedHash,
                    actualHash: $actualHash
                );
            }
            $modified++;
        }

        // New files: walk the current keys that aren't in the baseline.
        foreach ($currentEntries as $relative => $actualHash) {
            if (array_key_exists($relative, $baselineEntries)) {
                continue;
            }
            if (count($findings) < self::MAX_DETAILED_FINDINGS) {
                $findings[] = $this->finding(
                    kind: 'new',
                    relative: $relative,
                    expectedHash: null,
                    actualHash: $actualHash
                );
            }
            $new++;
        }

        $totalAltered = $modified + $missing + $new;
        if ($totalAltered > self::MAX_DETAILED_FINDINGS) {
            $findings[] = $this->massTamperingFinding($totalAltered, $modified, $missing, $new, $baseline);
        } elseif ($findings !== []) {
            $findings[] = $this->summaryFinding(
                $totalAltered,
                $modified,
                $missing,
                $new,
                count($baselineEntries),
                count($currentEntries),
                $baseline
            );
        }

        return $findings;
    }

    /**
     * Walk the configured roots and produce a `relative => hash` snapshot
     * the diff compares against. Shares its walker with the rebaseline
     * command via {@see BaselineBuilder} so a "scheduled run" and an
     * "operator-initiated rebaseline" never disagree on which files they
     * see.
     *
     * @return array<string,string>
     */
    private function snapshotCurrent(): array
    {
        // The version + edition values are informational on the baseline
        // we're throwing away — they're only persisted for baselines that
        // hit disk via `recon:integrity:rebaseline`.
        $snapshot = $this->baselineBuilder->build(magentoEdition: '', magentoVersion: '');
        return $snapshot->entries();
    }

    /**
     * @return array{id:string,title:string,severity:string,evidence:array<string,mixed>,remediation_url:string}
     */
    private function finding(string $kind, string $relative, ?string $expectedHash, ?string $actualHash): array
    {
        $severity = $this->severityFor($relative);

        return [
            'id' => self::ID,
            'title' => match ($kind) {
                'new' => sprintf('Unexpected new file: %s', $relative),
                'deleted' => sprintf('Baselined file missing: %s', $relative),
                default => sprintf('Altered file: %s', $relative),
            },
            'severity' => $severity,
            'evidence' => [
                'kind' => $kind,
                'file' => $relative,
                'absolute_path' => $this->magentoRoot->join($relative),
                'expected_sha' => $expectedHash,
                'actual_sha' => $actualHash,
            ],
            'remediation_url' => self::REMEDIATION_URL,
        ];
    }

    private function severityFor(string $relative): string
    {
        foreach (self::HIGH_SEVERITY_PREFIXES as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return Severity::HIGH;
            }
        }

        return Severity::MEDIUM;
    }

    /**
     * @return array{id:string,title:string,severity:string,evidence:array<string,mixed>,remediation_url:string}
     */
    private function noBaselineFinding(): array
    {
        return [
            'id' => self::NO_BASELINE_ID,
            'title' => 'Recon file-integrity baseline not yet generated',
            'severity' => Severity::LOW,
            'evidence' => [
                'kind' => 'no_baseline',
                'baseline_path' => $this->baselineRepository->path(),
                'remediation_command' => 'bin/magento recon:integrity:rebaseline',
            ],
            'remediation_url' => self::NO_BASELINE_REMEDIATION_URL,
        ];
    }

    /**
     * @return array{id:string,title:string,severity:string,evidence:array<string,mixed>,remediation_url:string}
     */
    private function massTamperingFinding(
        int $totalAltered,
        int $modified,
        int $missing,
        int $new,
        BaselineManifest $baseline
    ): array {
        return [
            'id' => self::ID,
            'title' => sprintf('More than %d altered files vs Recon baseline — likely compromised webroot', self::MAX_DETAILED_FINDINGS),
            'severity' => Severity::CRITICAL,
            'evidence' => [
                'kind' => 'mass_tampering',
                'altered_files_total' => $totalAltered,
                'modified_count' => $modified,
                'missing_count' => $missing,
                'new_count' => $new,
                'baseline_generated_at' => $baseline->generatedAt(),
                'baseline_entry_count' => $baseline->count(),
                'detailed_findings_truncated_at' => self::MAX_DETAILED_FINDINGS,
            ],
            'remediation_url' => self::MASS_TAMPERING_REMEDIATION_URL,
        ];
    }

    /**
     * @return array{id:string,title:string,severity:string,evidence:array<string,mixed>,remediation_url:string}
     */
    private function summaryFinding(
        int $totalAltered,
        int $modified,
        int $missing,
        int $new,
        int $baselineCount,
        int $currentCount,
        BaselineManifest $baseline
    ): array {
        return [
            'id' => self::ID,
            'title' => 'Recon file-integrity scan complete',
            'severity' => Severity::INFO,
            'evidence' => [
                'kind' => 'summary',
                'altered_files_total' => $totalAltered,
                'modified_count' => $modified,
                'missing_count' => $missing,
                'new_count' => $new,
                'baseline_entry_count' => $baselineCount,
                'current_entry_count' => $currentCount,
                'baseline_generated_at' => $baseline->generatedAt(),
            ],
            'remediation_url' => self::REMEDIATION_URL,
        ];
    }
}
