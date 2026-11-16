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
use DateTimeInterface;
use DateTimeZone;

/**
 * Static catalogue of known Magento patch releases and their ship dates.
 */
final class MagentoPatchCatalog
{
    /**
     * Patch releases keyed by version → release date (YYYY-MM-DD, UTC).
     *
     * Source: Adobe Commerce "Released versions" page
     * (https://experienceleague.adobe.com/en/docs/commerce-operations/release/versions).
     * The 2.4.7 – 2.4.9 lines were captured 2026-10-21 and the 2.4.4 –
     * 2.4.6 lines on 2026-11-16 (page last updated 2026-10-14). Every
     * date that has a `magento/magento2` GitHub release matches its
     * `published_at`. The Adobe Commerce-only extended support releases
     * (`2.4.4-p14` and later, `2.4.5-p15` and later) have no GitHub
     * release; their dates come from the Released versions page alone.
     * Only includes minor-line patch releases that ship security fixes.
     *
     * Keys must be Composer-published versions (`X.Y.Z` or `X.Y.Z-pN`).
     * Adobe's monthly isolated security patch labels (e.g. `2.4.9-2026-sep`)
     * are deliberately absent: they are patch files, never the installed
     * `magento/product-community-edition` version, and they do not order
     * correctly under {@see version_compare()} (see IronCartM2#206).
     *
     * @var array<string,string>
     */
    private const RELEASES = [
        '2.4.4'    => '2022-04-12',
        '2.4.4-p1' => '2022-08-09',
        '2.4.4-p2' => '2022-10-11',
        '2.4.4-p3' => '2023-03-14',
        '2.4.4-p4' => '2023-06-13',
        '2.4.4-p5' => '2023-08-08',
        '2.4.4-p6' => '2023-10-10',
        '2.4.4-p7' => '2024-02-13',
        '2.4.4-p8' => '2024-04-09',
        '2.4.4-p9' => '2024-06-11',
        '2.4.4-p10' => '2024-08-13',
        '2.4.4-p11' => '2024-10-08',
        '2.4.4-p12' => '2025-02-11',
        '2.4.4-p13' => '2025-04-08',
        '2.4.4-p14' => '2025-06-05',
        '2.4.4-p15' => '2025-08-12',
        '2.4.4-p16' => '2025-10-14',
        '2.4.4-p17' => '2026-03-10',
        '2.4.4-p18' => '2026-05-12',
        '2.4.5'    => '2022-08-09',
        '2.4.5-p1' => '2022-10-11',
        '2.4.5-p2' => '2023-03-14',
        '2.4.5-p3' => '2023-06-13',
        '2.4.5-p4' => '2023-08-08',
        '2.4.5-p5' => '2023-10-10',
        '2.4.5-p6' => '2024-02-13',
        '2.4.5-p7' => '2024-04-09',
        '2.4.5-p8' => '2024-06-11',
        '2.4.5-p9' => '2024-08-13',
        '2.4.5-p10' => '2024-10-08',
        '2.4.5-p11' => '2025-02-11',
        '2.4.5-p12' => '2025-04-08',
        '2.4.5-p13' => '2025-06-10',
        '2.4.5-p14' => '2025-08-12',
        '2.4.5-p15' => '2025-10-14',
        '2.4.5-p16' => '2026-03-10',
        '2.4.5-p17' => '2026-05-12',
        '2.4.6'    => '2023-03-14',
        '2.4.6-p1' => '2023-06-13',
        '2.4.6-p2' => '2023-08-08',
        '2.4.6-p3' => '2023-10-10',
        '2.4.6-p4' => '2024-02-13',
        '2.4.6-p5' => '2024-04-09',
        '2.4.6-p6' => '2024-06-11',
        '2.4.6-p7' => '2024-08-13',
        '2.4.6-p8' => '2024-10-08',
        '2.4.6-p9' => '2025-02-11',
        '2.4.6-p10' => '2025-04-08',
        '2.4.6-p11' => '2025-06-10',
        '2.4.6-p12' => '2025-08-12',
        '2.4.6-p13' => '2025-10-14',
        '2.4.6-p14' => '2026-03-10',
        '2.4.6-p15' => '2026-05-12',
        '2.4.7'    => '2024-04-09',
        '2.4.7-p1' => '2024-06-11',
        '2.4.7-p2' => '2024-08-13',
        '2.4.7-p3' => '2024-10-08',
        '2.4.7-p4' => '2025-02-11',
        '2.4.7-p5' => '2025-04-08',
        '2.4.7-p6' => '2025-06-10',
        '2.4.7-p7' => '2025-08-12',
        '2.4.7-p8' => '2025-10-14',
        '2.4.7-p9' => '2026-03-10',
        '2.4.7-p10' => '2026-05-12',
        '2.4.8'    => '2025-04-08',
        '2.4.8-p1' => '2025-06-10',
        '2.4.8-p2' => '2025-08-12',
        '2.4.8-p3' => '2025-10-14',
        '2.4.8-p4' => '2026-03-10',
        '2.4.8-p5' => '2026-05-12',
        '2.4.9'    => '2026-05-12',
    ];

    /**
     * Adobe's monthly isolated security patch trains, oldest first.
     *
     * Each train is a set of patch files built against the latest `-pN`
     * of every line it covers. It is never a Composer version, so labels
     * are compared by `released` date only, never with
     * {@see version_compare()}. `commerce` / `open_source` list the lines
     * that have a fix in the bulletin's Solution table for that edition.
     * Source: each bulletin's "Date published" and Solution table on
     * https://www.adobe.com/trust/security/products/commerce/<id>.html
     * (captured 2026-11-16; no November 2026 Commerce bulletin).
     *
     * @var list<array{label:string,released:string,bulletin:string,commerce:list<string>,open_source:list<string>}>
     */
    private const ISOLATED_PATCHES = [
        [
            'label' => '2026-jul',
            'released' => '2026-07-14',
            'bulletin' => 'APSB26-73',
            'commerce' => ['2.4.4', '2.4.5', '2.4.6', '2.4.7', '2.4.8', '2.4.9'],
            'open_source' => ['2.4.6', '2.4.7', '2.4.8', '2.4.9'],
        ],
        [
            'label' => '2026-aug',
            'released' => '2026-08-11',
            'bulletin' => 'APSB26-92',
            'commerce' => ['2.4.4', '2.4.5', '2.4.6', '2.4.7', '2.4.8', '2.4.9'],
            'open_source' => ['2.4.6', '2.4.7', '2.4.8', '2.4.9'],
        ],
        [
            'label' => '2026-sep',
            'released' => '2026-09-08',
            'bulletin' => 'APSB26-138',
            'commerce' => ['2.4.4', '2.4.5', '2.4.6', '2.4.7', '2.4.8', '2.4.9'],
            'open_source' => ['2.4.7', '2.4.8', '2.4.9'],
        ],
        [
            'label' => '2026-oct',
            'released' => '2026-10-13',
            'bulletin' => 'APSB26-153',
            'commerce' => ['2.4.4', '2.4.5', '2.4.6', '2.4.7', '2.4.8', '2.4.9'],
            'open_source' => ['2.4.7', '2.4.8', '2.4.9'],
        ],
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
     * Return the isolated security patch trains, oldest first.
     *
     * @return list<array{label:string,released:string,bulletin:string,commerce:list<string>,open_source:list<string>}>
     */
    public static function isolatedPatches(): array
    {
        return self::ISOLATED_PATCHES;
    }

    /**
     * Labels of the isolated patch trains Adobe has published for the
     * running version's line and edition since that version shipped.
     *
     * Returns an empty list unless `$version` is the latest catalogued
     * release on its line: Adobe builds isolated patches only against
     * the latest `-pN`, and a store behind it is graded on that gap.
     * Edition `Community` is Magento Open Source; any other edition is
     * treated as Adobe Commerce. Trains dated after `$now` are ignored.
     *
     * @return list<string>
     */
    public static function isolatedPatchesSince(string $version, string $edition, DateTimeInterface $now): array
    {
        $released = self::releaseDate($version);
        $line = self::minorLine($version);
        if ($released === null || $line === null || self::latestInMinorLine($version) !== $version) {
            return [];
        }

        $editionKey = $edition === 'Community' ? 'open_source' : 'commerce';
        $labels = [];
        foreach (self::ISOLATED_PATCHES as $train) {
            $trainDate = new DateTimeImmutable($train['released'], new DateTimeZone('UTC'));
            if (
                in_array($line, $train[$editionKey], true)
                && $trainDate > $released
                && $trainDate <= $now
            ) {
                $labels[] = $train['label'];
            }
        }

        return $labels;
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
     * the given version (e.g. `2.4.7` → `2.4.7-p10`).
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
