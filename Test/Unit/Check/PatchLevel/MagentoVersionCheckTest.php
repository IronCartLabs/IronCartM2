<?php

/**
 * IronCart_Scan — IC-001 unit tests.
 *
 * Exercises the version/edition reporting and the days-behind → severity
 * mapping documented in {@see \IronCart\Scan\Check\PatchLevel\MagentoVersionCheck}.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\PatchLevel;

use DateTimeImmutable;
use DateTimeZone;
use IronCart\Scan\Check\PatchLevel\MagentoPatchCatalog;
use IronCart\Scan\Check\PatchLevel\MagentoVersionCheck;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\ProductMetadataInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\PatchLevel\MagentoVersionCheck
 * @covers \IronCart\Scan\Check\PatchLevel\MagentoPatchCatalog
 */
class MagentoVersionCheckTest extends TestCase
{
    public function testReportsLatestPatchAsInfo(): void
    {
        // 2.4.9 (2026-05-12) is the only Composer release on the 2.4.9 line;
        // the monthly isolated patches since then are not versions.
        $check = $this->makeCheck('2.4.9', 'Community', '2026-10-21');

        $findings = $check->run();
        self::assertCount(1, $findings);

        $finding = $findings[0];
        self::assertSame('IC-001', $finding['id']);
        self::assertSame(Severity::INFO, $finding['severity']);
        self::assertSame('2.4.9', $finding['evidence']['version']);
        self::assertSame('Community', $finding['evidence']['edition']);
        self::assertSame('2.4.9', $finding['evidence']['latest_known_in_line']);
        self::assertSame(0, $finding['evidence']['days_behind_latest']);
        self::assertTrue($finding['evidence']['catalog_known']);
    }

    public function testReportsHighWhenThirtyToNinetyDaysBehind(): void
    {
        // Running 2.4.8-p4 (released 2026-03-10), latest in line is
        // 2.4.8-p5 (released 2026-05-12). Gap = 63 days → high.
        $check = $this->makeCheck('2.4.8-p4', 'Community', '2026-10-21');
        $finding = $check->run()[0];

        self::assertSame(Severity::HIGH, $finding['severity']);
        self::assertSame(63, $finding['evidence']['days_behind_latest']);
        self::assertSame('2.4.8-p5', $finding['evidence']['latest_known_in_line']);
    }

    public function testOldTwoFourEightStoreGradesCritical(): void
    {
        // Before the 2026-10 refresh the catalogue had no 2.4.8 entries, so
        // every 2.4.8 store graded INFO. 2.4.8 GA (2025-04-08) → 2.4.8-p5
        // (2026-05-12) = 399 days → critical.
        $check = $this->makeCheck('2.4.8', 'Community', '2026-10-21');
        $finding = $check->run()[0];

        self::assertSame(Severity::CRITICAL, $finding['severity']);
        self::assertSame(399, $finding['evidence']['days_behind_latest']);
        self::assertSame('2.4.8-p5', $finding['evidence']['latest_known_in_line']);
        self::assertTrue($finding['evidence']['catalog_known']);
    }

    public function testTwoFourSevenP5NoLongerReadsAsCurrent(): void
    {
        // 2.4.7-p5 (2025-04-08) was the newest 2.4.7 entry before the
        // refresh; 2.4.7-p10 (2026-05-12) has since shipped.
        $check = $this->makeCheck('2.4.7-p5', 'Community', '2026-10-21');
        $finding = $check->run()[0];

        self::assertNotSame(Severity::INFO, $finding['severity']);
        self::assertSame(Severity::CRITICAL, $finding['severity']);
        self::assertSame('2.4.7-p10', $finding['evidence']['latest_known_in_line']);
    }

    public function testPatchTenOrdersAbovePatchNine(): void
    {
        // Two-digit -pN suffixes must not sort lexically (p10 < p9).
        self::assertSame('2.4.7-p10', MagentoPatchCatalog::latestInMinorLine('2.4.7-p9'));
    }

    public function testCatalogKeysAreComposerVersionsOnly(): void
    {
        // Guard against non-version_compare()-safe keys such as Adobe's
        // isolated-patch labels (`2.4.9-2026-sep`); see IronCartM2#206.
        foreach (array_keys(MagentoPatchCatalog::all()) as $version) {
            self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-p\d+)?$/', $version);
        }
    }

    public function testCatalogDatesIncreaseWithVersionWithinEachLine(): void
    {
        $byLine = [];
        foreach (MagentoPatchCatalog::all() as $version => $date) {
            $byLine[explode('-', $version)[0]][$version] = $date;
        }

        foreach ($byLine as $line => $releases) {
            uksort($releases, 'version_compare');
            $dates = array_values($releases);
            $sorted = $dates;
            sort($sorted);
            self::assertSame($sorted, $dates, sprintf('Release dates out of order on the %s line', $line));
        }
    }

    public function testReportsCriticalWhenMoreThanNinetyDaysBehind(): void
    {
        // Running 2.4.6-p9 (2024-10-08), latest 2.4.6-p10 (2025-02-11).
        // Gap = 126 days → critical.
        $check = $this->makeCheck('2.4.6-p9', 'Community', '2025-03-01');
        $finding = $check->run()[0];

        self::assertSame(Severity::CRITICAL, $finding['severity']);
        self::assertGreaterThan(90, $finding['evidence']['days_behind_latest']);
        self::assertSame('2.4.6-p10', $finding['evidence']['latest_known_in_line']);
    }

    public function testUnknownVersionFallsBackToInfo(): void
    {
        $check = $this->makeCheck('9.9.9-future', 'Enterprise', '2025-01-01');
        $finding = $check->run()[0];

        self::assertSame(Severity::INFO, $finding['severity']);
        self::assertFalse($finding['evidence']['catalog_known']);
        self::assertSame('Enterprise', $finding['evidence']['edition']);
    }

    public function testEvidenceFieldsArePresent(): void
    {
        $check = $this->makeCheck('2.4.7-p4', 'Community', '2025-04-09');
        $finding = $check->run()[0];

        foreach (
            [
                'version',
                'edition',
                'latest_known_in_line',
                'latest_known_in_line_released',
                'running_version_released',
                'days_behind_latest',
                'catalog_known',
            ] as $key
        ) {
            self::assertArrayHasKey($key, $finding['evidence'], $key);
        }

        self::assertSame(
            MagentoVersionCheck::REMEDIATION_URL,
            $finding['remediation_url']
        );
    }

    public function testFutureVersionDoesNotGoNegative(): void
    {
        // Running 2.4.7-p10 (latest known) → 0 days behind, info.
        $check = $this->makeCheck('2.4.7-p10', 'Community', '2025-01-01');
        $finding = $check->run()[0];

        self::assertSame(0, $finding['evidence']['days_behind_latest']);
        self::assertSame(Severity::INFO, $finding['severity']);
    }

    private function makeCheck(string $version, string $edition, string $now): MagentoVersionCheck
    {
        $metadata = $this->createStub(ProductMetadataInterface::class);
        $metadata->method('getVersion')->willReturn($version);
        $metadata->method('getEdition')->willReturn($edition);

        return new MagentoVersionCheck(
            $metadata,
            new DateTimeImmutable($now, new DateTimeZone('UTC'))
        );
    }
}
