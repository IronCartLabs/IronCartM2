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
}
