<?php

/**
 * IronCart_Scan — Magento patch-release catalogue.
 *
 * Hard-coded list of Magento Open Source / Adobe Commerce patch releases
 * and the dates they shipped. IC-001 uses this catalogue to map the
 * detected `magento/product-community-edition` version to a "days behind
 * latest" age figure for severity grading.
 *
 * The catalogue is intentionally bundled (not fetched at runtime) because
 * v0 forbids outbound network calls. The list is conservative — only
 * security or quality-fix patch releases are listed, and it must be
 * refreshed by hand alongside `data/osv-magento.json` (see
 * `data/README.md`). Automation lands in v2.
 *
 * Dates are ISO-8601 (YYYY-MM-DD) UTC. Versions are PHP-comparable via
 * {@see version_compare()}.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\PatchLevel;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Static catalogue of known Magento patch releases and their ship dates.
 */
final class MagentoPatchCatalog
{
    /**
     * Patch releases keyed by version → release date (YYYY-MM-DD, UTC).
     *
     * Source: Magento Security Center release notes, captured 2026-05-14.
     * Only includes minor-line patch releases that ship security fixes.
     *
     * @var array<string,string>
     */
    private const RELEASES = [
        '2.4.4'    => '2022-04-12',
        '2.4.4-p1' => '2022-08-09',
        '2.4.4-p2' => '2022-10-11',
        '2.4.4-p3' => '2023-01-10',
        '2.4.4-p4' => '2023-04-11',
        '2.4.4-p5' => '2023-06-13',
        '2.4.4-p6' => '2023-08-08',
        '2.4.4-p7' => '2023-10-10',
        '2.4.4-p8' => '2024-01-09',
        '2.4.4-p9' => '2024-04-09',
        '2.4.4-p10' => '2024-06-11',
        '2.4.4-p11' => '2024-08-13',
        '2.4.4-p12' => '2024-10-08',
        '2.4.5'    => '2022-08-09',
        '2.4.5-p1' => '2022-10-11',
        '2.4.5-p2' => '2023-01-10',
        '2.4.5-p3' => '2023-04-11',
        '2.4.5-p4' => '2023-06-13',
        '2.4.5-p5' => '2023-08-08',
        '2.4.5-p6' => '2023-10-10',
        '2.4.5-p7' => '2024-01-09',
        '2.4.5-p8' => '2024-04-09',
        '2.4.5-p9' => '2024-06-11',
        '2.4.5-p10' => '2024-08-13',
        '2.4.5-p11' => '2024-10-08',
        '2.4.6'    => '2023-03-14',
        '2.4.6-p1' => '2023-04-11',
        '2.4.6-p2' => '2023-06-13',
        '2.4.6-p3' => '2023-08-08',
        '2.4.6-p4' => '2023-10-10',
        '2.4.6-p5' => '2024-01-09',
        '2.4.6-p6' => '2024-04-09',
        '2.4.6-p7' => '2024-06-11',
        '2.4.6-p8' => '2024-08-13',
        '2.4.6-p9' => '2024-10-08',
        '2.4.6-p10' => '2025-02-11',
        '2.4.7'    => '2024-03-12',
        '2.4.7-p1' => '2024-06-11',
        '2.4.7-p2' => '2024-08-13',
        '2.4.7-p3' => '2024-10-08',
        '2.4.7-p4' => '2025-02-11',
        '2.4.7-p5' => '2025-04-08',
    ];

    private function __construct()
    {
    }

    /**
     * Return the catalogue as `version => YYYY-MM-DD` pairs.
     *
     * @return array<string,string>
     */
    public static function all(): array
    {
        return self::RELEASES;
    }

    /**
     * Lookup the release date for a specific Magento patch version.
     *
     * Returns null when the version is not in the catalogue (e.g. a
     * development build or a release newer than the bundled snapshot).
     */
    public static function releaseDate(string $version): ?DateTimeImmutable
    {
        $iso = self::RELEASES[$version] ?? null;
        if ($iso === null) {
            return null;
        }

        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    /**
     * Return the latest known release in the same `MAJOR.MINOR` line as
     * the given version (e.g. `2.4.7` → `2.4.7-p5`).
     *
     * Falls back to null when the minor line is not represented.
     */
    public static function latestInMinorLine(string $version): ?string
    {
        $line = self::minorLine($version);
        if ($line === null) {
            return null;
        }

        $candidates = [];
        foreach (self::RELEASES as $release => $_date) {
            if (self::minorLine($release) === $line) {
                $candidates[] = $release;
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (string $a, string $b): int => version_compare($a, $b));

        return $candidates[array_key_last($candidates)];
    }

    /**
     * Extract the `MAJOR.MINOR.PATCH` portion of a version (strip
     * `-pN` / `-betaN` suffix), or null if it cannot be parsed.
     */
    private static function minorLine(string $version): ?string
    {
        if (!preg_match('/^(\d+\.\d+\.\d+)/', $version, $m)) {
            return null;
        }

        return $m[1];
    }
}
