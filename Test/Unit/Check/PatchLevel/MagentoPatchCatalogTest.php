<?php

/**
 * IronCart_Scan — IC-001 patch catalogue unit tests.
 *
 * Catalogue-only assertions with no Magento types, so they run in the CI
 * unit job while MagentoVersionCheckTest waits on IronCartM2#216.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\PatchLevel;

use DateTimeImmutable;
use DateTimeZone;
use IronCart\Scan\Check\PatchLevel\MagentoPatchCatalog;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\PatchLevel\MagentoPatchCatalog
 */
class MagentoPatchCatalogTest extends TestCase
{
    /**
     * Every legacy line runs from GA through its latest -pN with no gaps,
     * and the latest shipped in Adobe's 2026-05-12 cycle (IronCartM2#220).
     *
     * @dataProvider legacyLines
     */
    public function testLegacyLineIsCompleteThroughLatest(string $line, int $lastPatch): void
    {
        self::assertSame($line . '-p' . $lastPatch, MagentoPatchCatalog::latestInMinorLine($line));
        self::assertSame(
            '2026-05-12',
            MagentoPatchCatalog::releaseDate($line . '-p' . $lastPatch)?->format('Y-m-d')
        );

        self::assertNotNull(MagentoPatchCatalog::releaseDate($line), $line);
        for ($patch = 1; $patch <= $lastPatch; $patch++) {
            self::assertNotNull(MagentoPatchCatalog::releaseDate($line . '-p' . $patch), $line . '-p' . $patch);
        }
    }

    /**
     * @return array<string,array{string,int}>
     */
    public static function legacyLines(): array
    {
        return [
            '2.4.4' => ['2.4.4', 18],
            '2.4.5' => ['2.4.5', 17],
            '2.4.6' => ['2.4.6', 15],
        ];
    }

    public function testTwoFourSixP10IsThreeHundredNinetyNineDaysBehindLatest(): void
    {
        $running = MagentoPatchCatalog::releaseDate('2.4.6-p10');
        $latest = MagentoPatchCatalog::releaseDate('2.4.6-p15');

        self::assertNotNull($running);
        self::assertNotNull($latest);
        self::assertSame('2025-04-08', $running->format('Y-m-d'));
        self::assertSame(399, (int) $running->diff($latest)->days);
    }

    /**
     * Guards the isolated-patch data (IronCartM2#221): labels match their
     * release month, trains are in date order, and every line is a
     * catalogued line. Open Source never gets a line Commerce lacks.
     */
    public function testIsolatedPatchDataIsWellFormed(): void
    {
        $lines = [];
        foreach (array_keys(MagentoPatchCatalog::all()) as $version) {
            $lines[explode('-', $version)[0]] = true;
        }

        $previous = '';
        foreach (MagentoPatchCatalog::isolatedPatches() as $train) {
            $released = new DateTimeImmutable($train['released'], new DateTimeZone('UTC'));
            self::assertSame($train['released'], $released->format('Y-m-d'));
            self::assertSame(strtolower($released->format('Y-M')), $train['label']);
            self::assertGreaterThan($previous, $train['released'], $train['label']);
            self::assertMatchesRegularExpression('/^APSB\d{2}-\d+$/', $train['bulletin']);
            self::assertSame([], array_diff($train['open_source'], $train['commerce']), $train['label']);
            foreach ($train['commerce'] as $line) {
                self::assertArrayHasKey($line, $lines, $train['label'] . ' ' . $line);
            }
            $previous = $train['released'];
        }
    }

    /**
     * @dataProvider isolatedPatchesSinceCases
     * @param list<string> $expected
     */
    public function testIsolatedPatchesSince(string $version, string $edition, string $now, array $expected): void
    {
        self::assertSame(
            $expected,
            MagentoPatchCatalog::isolatedPatchesSince(
                $version,
                $edition,
                new DateTimeImmutable($now, new DateTimeZone('UTC'))
            )
        );
    }

    /**
     * @return array<string,array{string,string,string,list<string>}>
     */
    public static function isolatedPatchesSinceCases(): array
    {
        $all = ['2026-jul', '2026-aug', '2026-sep', '2026-oct'];

        return [
            'latest Commerce 2.4.8, all four trains' => ['2.4.8-p5', 'Enterprise', '2026-11-16', $all],
            'latest Open Source 2.4.9, all four trains' => ['2.4.9', 'Community', '2026-11-16', $all],
            'latest Commerce 2.4.4, all four trains' => ['2.4.4-p18', 'Enterprise', '2026-11-16', $all],
            'Open Source 2.4.6, no Sep/Oct' =>['2.4.6-p15', 'Community', '2026-11-16', ['2026-jul', '2026-aug']],
            'trains after now are ignored' => ['2.4.7-p10', 'Community', '2026-09-01', ['2026-jul', '2026-aug']],
            'before the first train' => ['2.4.9', 'Community', '2026-07-13', []],
            'behind latest -pN' => ['2.4.8-p4', 'Enterprise', '2026-11-16', []],
            'version not in catalogue' => ['2.4.9-p1', 'Enterprise', '2026-11-16', []],
        ];
    }
}
