<?php

/**
 * IronCart_Scan — FindingCountExtractor unit test.
 *
 * Pins the pure pipeline that the BackfillFindingCounts data patch
 * uses to seed `ironcart_scan_run.finding_count` from existing
 * `summary_json` blobs (issue #118). The extractor is also the
 * read-side coercer for any future code that wants to display a count
 * for a row the consumer hasn't yet rewritten.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Report;

use IronCart\Scan\Report\FindingCountExtractor;
use PHPUnit\Framework\TestCase;

class FindingCountExtractorTest extends TestCase
{
    public function testReturnsNullForNullInput(): void
    {
        self::assertNull(FindingCountExtractor::fromSummaryJson(null));
    }

    public function testReturnsNullForEmptyString(): void
    {
        self::assertNull(FindingCountExtractor::fromSummaryJson(''));
    }

    public function testReturnsNullForMalformedJson(): void
    {
        self::assertNull(FindingCountExtractor::fromSummaryJson('{not json'));
    }

    public function testReturnsNullForNonObjectJson(): void
    {
        self::assertNull(FindingCountExtractor::fromSummaryJson('"a string"'));
        self::assertNull(FindingCountExtractor::fromSummaryJson('42'));
    }

    public function testReadsCanonicalFindingCountKey(): void
    {
        $json = json_encode([
            'totals' => ['critical' => 1, 'high' => 2, 'medium' => 0, 'low' => 0, 'info' => 0],
            'finding_count' => 3,
            'magento' => ['version' => '2.4.7-p5', 'edition' => 'Open Source'],
            'schema_version' => 'v0',
        ]);
        self::assertSame(3, FindingCountExtractor::fromSummaryJson((string)$json));
    }

    public function testCoercesNumericStringToInt(): void
    {
        // Third-party producers (or a future migration) could feasibly
        // emit the count as a string. Coerce, don't crash.
        $json = '{"finding_count":"7"}';
        self::assertSame(7, FindingCountExtractor::fromSummaryJson($json));
    }

    public function testReturnsNullForNonNumericFindingCount(): void
    {
        $json = '{"finding_count":"not a number"}';
        self::assertNull(FindingCountExtractor::fromSummaryJson($json));
    }

    public function testReturnsNullForNegativeFindingCount(): void
    {
        // A negative count is structurally impossible — refuse it so
        // the row stays out of any numeric-range filter window.
        $json = '{"finding_count":-1}';
        self::assertNull(FindingCountExtractor::fromSummaryJson($json));
    }

    public function testReturnsZeroForExplicitZero(): void
    {
        // A clean-bill-of-health run that emitted no findings legitimately
        // has finding_count=0 — that is distinct from null (queued /
        // failed). Admins filtering for "≤ 0 findings" should match.
        self::assertSame(0, FindingCountExtractor::fromSummaryJson('{"finding_count":0}'));
    }

    public function testSumsTotalsWhenFindingCountKeyAbsent(): void
    {
        // Older rows (or hand-edited fixtures) might carry totals but
        // not the convenience `finding_count`. Backfill derives by
        // summing severities so those rows still seed correctly.
        $json = json_encode([
            'totals' => ['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4, 'info' => 5],
        ]);
        self::assertSame(15, FindingCountExtractor::fromSummaryJson((string)$json));
    }

    public function testReturnsNullForErrorEnvelope(): void
    {
        // Failed runs carry an `error` envelope and no totals — backfill
        // must skip these rather than synthesise a 0.
        $json = json_encode([
            'error' => ['class' => 'RuntimeException', 'message' => 'boom'],
        ]);
        self::assertNull(FindingCountExtractor::fromSummaryJson((string)$json));
    }

    public function testIgnoresNonNumericSeverityEntriesWhenSummingTotals(): void
    {
        $json = '{"totals":{"critical":"junk","high":2,"medium":3}}';
        self::assertSame(5, FindingCountExtractor::fromSummaryJson($json));
    }

    public function testReturnsNullWhenTotalsHasOnlyJunk(): void
    {
        $json = '{"totals":{"critical":"x","high":"y"}}';
        self::assertNull(FindingCountExtractor::fromSummaryJson($json));
    }
}
