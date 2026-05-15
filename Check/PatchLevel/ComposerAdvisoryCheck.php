<?php

/**
 * IronCart_Scan — IC-002 Composer advisory cross-reference.
 *
 * Walks the project's `composer.lock`, looks every installed package up
 * in the bundled OSV snapshot, and emits one finding per package that
 * has an unresolved advisory affecting the installed version.
 *
 * Severity is graded by how long the advisory has been published:
 *
 *   - `critical` if published > 90 days ago
 *   - `high`     if 30–90 days ago
 *   - `medium`   if 0–30 days ago
 *
 * The OSV snapshot pre-classifies advisories with a `severity` field
 * (sourced from OSV.dev). We use the higher of the two so a freshly
 * disclosed critical doesn't get downgraded to `medium` just because
 * it's less than 30 days old.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\PatchLevel;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Report\Severity;
use Throwable;

/**
 * IC-002 — Composer advisory cross-reference against the bundled OSV snapshot.
 */
class ComposerAdvisoryCheck implements CheckInterface
{
    public const ID = 'IC-002';

    public const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-002';

    /**
     * Severity ranking (lower index = more severe). Lets us pick the
     * worse of the OSV-declared severity and the age-derived severity.
     *
     * @var list<string>
     */
    private const SEVERITY_RANK = [
        Severity::CRITICAL,
        Severity::HIGH,
        Severity::MEDIUM,
        Severity::LOW,
        Severity::INFO,
    ];

    public function __construct(
        private readonly ComposerLockReader $lockReader,
        private readonly OsvSnapshotLoader $snapshotLoader,
        private readonly ?DateTimeImmutable $now = null
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function title(): string
    {
        return 'Composer advisories (OSV snapshot)';
    }

    public function run(): array
    {
        try {
            $installed = $this->lockReader->packages();
        } catch (Throwable $e) {
            return [[
                'id' => self::ID,
                'title' => $this->title(),
                'severity' => Severity::INFO,
                'evidence' => [
                    'status' => 'composer.lock unavailable',
                    'reason' => $e->getMessage(),
                ],
                'remediation_url' => self::REMEDIATION_URL,
            ]];
        }

        try {
            $advisories = $this->snapshotLoader->advisoriesByPackage();
        } catch (Throwable $e) {
            return [[
                'id' => self::ID,
                'title' => $this->title(),
                'severity' => Severity::INFO,
                'evidence' => [
                    'status' => 'OSV snapshot unavailable',
                    'reason' => $e->getMessage(),
                ],
                'remediation_url' => self::REMEDIATION_URL,
            ]];
        }

        $now = $this->now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $findings = [];

        foreach ($installed as $package => $version) {
            $packageAdvisories = $advisories[$package] ?? [];
            foreach ($packageAdvisories as $advisory) {
                if (!self::versionAffected($version, $advisory['affected'])) {
                    continue;
                }
                $findings[] = self::makeFinding($package, $version, $advisory, $now);
            }
        }

        return $findings;
    }

    /**
     * Return true when `$version` falls inside any of the OSV
     * `introduced`/`fixed` ranges. An open-ended range (no `fixed`
     * key) is treated as "still affected".
     *
     * @param list<array<string,string>> $affected
     */
    private static function versionAffected(string $version, array $affected): bool
    {
        if ($affected === []) {
            // No range data — be conservative and report.
            return true;
        }

        foreach ($affected as $range) {
            $introduced = $range['introduced'] ?? '0';
            if ($introduced !== '0' && version_compare($version, $introduced, '<')) {
                continue;
            }
            if (isset($range['fixed']) && version_compare($version, $range['fixed'], '>=')) {
                continue;
            }
            if (isset($range['last_affected']) && version_compare($version, $range['last_affected'], '>')) {
                continue;
            }
            return true;
        }

        return false;
    }

    /**
     * Build a v0 finding for a single (package, advisory) pair.
     *
     * @param array{
     *     id:string,
     *     aliases:list<string>,
     *     summary:string,
     *     published:string,
     *     severity:string,
     *     package:string,
     *     affected:list<array<string,string>>,
     *     reference:string
     * } $advisory
     *
     * @return array{
     *     id:string,
     *     title:string,
     *     severity:string,
     *     evidence:array<string,mixed>,
     *     remediation_url:string
     * }
     */
    private static function makeFinding(
        string $package,
        string $installedVersion,
        array $advisory,
        DateTimeInterface $now
    ): array {
        $published = self::parseDate($advisory['published']);
        $daysSincePublished = self::daysSince($published, $now);
        $ageSeverity = self::gradeByAge($daysSincePublished);
        $declaredSeverity = self::normaliseSeverity($advisory['severity']);
        $severity = self::worstOf($declaredSeverity, $ageSeverity);

        $fixedVersions = [];
        foreach ($advisory['affected'] as $range) {
            if (isset($range['fixed'])) {
                $fixedVersions[] = $range['fixed'];
            }
        }

        return [
            'id' => self::ID,
            'title' => sprintf('%s — %s', $package, $advisory['id'] !== '' ? $advisory['id'] : 'advisory'),
            'severity' => $severity,
            'evidence' => [
                'package' => $package,
                'installed_version' => $installedVersion,
                'advisory_id' => $advisory['id'],
                'aliases' => $advisory['aliases'],
                'summary' => $advisory['summary'],
                'published' => $advisory['published'],
                'days_since_published' => $daysSincePublished,
                'declared_severity' => $declaredSeverity,
                'age_severity' => $ageSeverity,
                'fixed_in' => array_values(array_unique($fixedVersions)),
                'reference_url' => $advisory['reference'] !== ''
                    ? $advisory['reference']
                    : sprintf('https://osv.dev/vulnerability/%s', $advisory['id']),
            ],
            'remediation_url' => self::REMEDIATION_URL,
        ];
    }

    private static function parseDate(string $iso): ?DateTimeImmutable
    {
        if ($iso === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private static function daysSince(?DateTimeInterface $when, DateTimeInterface $now): ?int
    {
        if ($when === null) {
            return null;
        }
        $diff = $now->getTimestamp() - $when->getTimestamp();
        if ($diff <= 0) {
            return 0;
        }
        return (int) floor($diff / 86400);
    }

    private static function gradeByAge(?int $days): string
    {
        if ($days === null) {
            return Severity::MEDIUM;
        }
        if ($days > 90) {
            return Severity::CRITICAL;
        }
        if ($days >= 30) {
            return Severity::HIGH;
        }
        return Severity::MEDIUM;
    }

    private static function normaliseSeverity(string $declared): string
    {
        $declared = strtolower($declared);
        return Severity::isValid($declared) ? $declared : Severity::MEDIUM;
    }

    /**
     * Return whichever of the two severities ranks worst (smallest index).
     */
    private static function worstOf(string $a, string $b): string
    {
        $rankA = array_search($a, self::SEVERITY_RANK, true);
        $rankB = array_search($b, self::SEVERITY_RANK, true);
        if ($rankA === false) {
            return $b;
        }
        if ($rankB === false) {
            return $a;
        }
        return $rankA < $rankB ? $a : $b;
    }
}
