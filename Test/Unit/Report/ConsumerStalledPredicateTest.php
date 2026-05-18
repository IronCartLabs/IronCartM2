<?php

/**
 * IronCart_Scan — stuck-queue predicate tests (issue #92).
 *
 * Lives under Test/Unit/Report so the unit CI cell loads it (see
 * ci.yml — only Test/Unit/Report is enumerated in the override
 * phpunit.xml because that subtree has no Magento\Framework imports).
 * {@see ConsumerStalledPredicate} has no Magento autoload dependency
 * specifically so it can be exercised from this cell.
 *
 * Why pin the literal status string (and not import ScanRun::STATUS_QUEUED):
 * the unit CI cell runs without `magento/framework` on the classpath.
 * Importing IronCart\Scan\Model\ScanRun would pull AbstractModel into
 * the autoload chain and the test would fail to load. The same trick
 * {@see ScanRunTerminalStateTest} uses.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Report;

use IronCart\Scan\Model\Notification\ConsumerStalledPredicate;
use PHPUnit\Framework\TestCase;

class ConsumerStalledPredicateTest extends TestCase
{
    /**
     * Fixed "now" for boundary tests. Picked so the math is obvious
     * when reading the test: 1747531837 = 2025-05-17 22:50:37 UTC.
     */
    private const NOW_EPOCH = 1747531837;

    /**
     * The predicate parses started_at via strtotime(), which honours
     * the process's default timezone when the string carries no
     * explicit offset (which is the case for MySQL DATETIME / TIMESTAMP
     * columns). The CI unit cell may run in any timezone, so pin it to
     * UTC here and restore it in tearDown so test order is irrelevant.
     */
    private string $savedTimezone = '';

    protected function setUp(): void
    {
        $this->savedTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->savedTimezone);
    }

    public function testDefaultThresholdSecondsIsSixtySeconds(): void
    {
        // Pinned per the AC ("start with 60s; configurable via
        // ironcart_scan/runtime/consumer_alert_threshold_seconds"). The
        // value is mirrored in etc/config.xml's <default> block; if
        // anyone bumps one without the other, this test catches it on
        // the predicate side and the matching Report\AdminUiShapeTest
        // / DbSchemaShapeTest patterns can be extended later for the
        // config side. For now, the predicate's constant is the single
        // source of truth.
        self::assertSame(60, ConsumerStalledPredicate::DEFAULT_THRESHOLD_SECONDS);
    }

    public function testStatusConstantValueIsFrozen(): void
    {
        // Mirror of the ScanRunTerminalStateTest::testStatusConstantValuesAreFrozen
        // pattern. The predicate duplicates the literal because it is
        // Magento-free; this test stops drift between the two sources
        // of truth.
        self::assertSame('queued', ConsumerStalledPredicate::STATUS_QUEUED);
    }

    public function testReturnsFalseOnEmptyRowSet(): void
    {
        self::assertFalse(ConsumerStalledPredicate::hasStalledRow(
            [],
            60,
            self::NOW_EPOCH
        ));
    }

    public function testReturnsTrueWhenQueuedRowIsOlderThanThreshold(): void
    {
        // started_at: 120 seconds ago; threshold 60s → stuck.
        $rows = [
            [
                'status'     => 'queued',
                'started_at' => self::epochToMysql(self::NOW_EPOCH - 120),
            ],
        ];
        self::assertTrue(ConsumerStalledPredicate::hasStalledRow(
            $rows,
            60,
            self::NOW_EPOCH
        ));
    }

    public function testReturnsFalseWhenQueuedRowIsExactlyAtThreshold(): void
    {
        // age == threshold: predicate uses strict `>`, so exactly-at
        // the boundary is not yet stuck. This is the lower half of
        // the boundary AC (60s threshold → 60s-old row is still OK).
        $rows = [
            [
                'status'     => 'queued',
                'started_at' => self::epochToMysql(self::NOW_EPOCH - 60),
            ],
        ];
        self::assertFalse(ConsumerStalledPredicate::hasStalledRow(
            $rows,
            60,
            self::NOW_EPOCH
        ));
    }

    public function testReturnsTrueOneSecondPastThreshold(): void
    {
        // The upper half of the boundary AC: one second past the
        // threshold flips the predicate. Together with the previous
        // test this pins both sides of the boundary at second
        // precision.
        $rows = [
            [
                'status'     => 'queued',
                'started_at' => self::epochToMysql(self::NOW_EPOCH - 61),
            ],
        ];
        self::assertTrue(ConsumerStalledPredicate::hasStalledRow(
            $rows,
            60,
            self::NOW_EPOCH
        ));
    }

    public function testReturnsFalseWhenQueuedRowIsYoungerThanThreshold(): void
    {
        // Freshly-enqueued row that the consumer would normally pick up
        // within a couple of seconds. Predicate must NOT fire here
        // (the AC explicitly calls out clearing noise from just-
        // enqueued rows).
        $rows = [
            [
                'status'     => 'queued',
                'started_at' => self::epochToMysql(self::NOW_EPOCH - 5),
            ],
        ];
        self::assertFalse(ConsumerStalledPredicate::hasStalledRow(
            $rows,
            60,
            self::NOW_EPOCH
        ));
    }

    public function testIgnoresRunningRowsRegardlessOfAge(): void
    {
        // A running row is by definition being processed; even an
        // ancient started_at on a running row is not "stuck-consumer"
        // evidence. (Long-running scans are a separate issue with a
        // separate predicate, out of scope for #92.)
        $rows = [
            [
                'status'     => 'running',
                'started_at' => self::epochToMysql(self::NOW_EPOCH - 9999),
            ],
        ];
        self::assertFalse(ConsumerStalledPredicate::hasStalledRow(
            $rows,
            60,
            self::NOW_EPOCH
        ));
    }

    public function testIgnoresTerminalRows(): void
    {
        // Succeeded / failed rows are terminal; they cannot be evidence
        // of a stalled consumer. Includes a sanity row each.
        $rows = [
            [
                'status'     => 'succeeded',
                'started_at' => self::epochToMysql(self::NOW_EPOCH - 9999),
            ],
            [
                'status'     => 'failed',
                'started_at' => self::epochToMysql(self::NOW_EPOCH - 9999),
            ],
        ];
        self::assertFalse(ConsumerStalledPredicate::hasStalledRow(
            $rows,
            60,
            self::NOW_EPOCH
        ));
    }

    public function testReturnsTrueWhenAnySingleRowQualifies(): void
    {
        // Mixed batch: one stuck queued row alongside terminal/running
        // rows. The predicate short-circuits the moment any row
        // qualifies — order in the batch must not matter.
        $rows = [
            [
                'status'     => 'succeeded',
                'started_at' => self::epochToMysql(self::NOW_EPOCH - 5000),
            ],
            [
                'status'     => 'running',
                'started_at' => self::epochToMysql(self::NOW_EPOCH - 90),
            ],
            [
                'status'     => 'queued',
                'started_at' => self::epochToMysql(self::NOW_EPOCH - 120),
            ],
            [
                'status'     => 'failed',
                'started_at' => self::epochToMysql(self::NOW_EPOCH - 1),
            ],
        ];
        self::assertTrue(ConsumerStalledPredicate::hasStalledRow(
            $rows,
            60,
            self::NOW_EPOCH
        ));
    }

    public function testAcceptsIntegerStartedAtAsUnixEpoch(): void
    {
        // started_at is canonically stored as a MySQL DATETIME string,
        // but some testing/admin paths surface it as an int unix epoch.
        // The predicate accepts both so the test fixture doesn't have
        // to spell out the MySQL format every time.
        $rows = [
            [
                'status'     => 'queued',
                'started_at' => self::NOW_EPOCH - 120,
            ],
        ];
        self::assertTrue(ConsumerStalledPredicate::hasStalledRow(
            $rows,
            60,
            self::NOW_EPOCH
        ));
    }

    public function testIgnoresRowWithNullStartedAt(): void
    {
        // A row whose started_at hasn't been populated yet (theoretical
        // — the DB column defaults to CURRENT_TIMESTAMP, but a buggy
        // insert path could omit it) must not crash the predicate. We
        // treat "no started_at" as "not stuck" since we can't measure
        // age.
        $rows = [
            [
                'status'     => 'queued',
                'started_at' => null,
            ],
        ];
        self::assertFalse(ConsumerStalledPredicate::hasStalledRow(
            $rows,
            60,
            self::NOW_EPOCH
        ));
    }

    public function testIgnoresMalformedRows(): void
    {
        // The admin notice predicate runs on every adminhtml render;
        // any predicate crash would surface as a 500 on unrelated admin
        // pages. The predicate must be total over arbitrary input:
        // missing keys, wrong types, unparseable timestamps, etc., all
        // mean "not stuck" rather than "throw."
        $rows = [
            // Missing status.
            ['started_at' => self::epochToMysql(self::NOW_EPOCH - 120)],
            // Non-string status.
            ['status' => 42, 'started_at' => self::epochToMysql(self::NOW_EPOCH - 120)],
            // Unparseable started_at.
            ['status' => 'queued', 'started_at' => 'not-a-timestamp'],
            // Empty string started_at.
            ['status' => 'queued', 'started_at' => ''],
            // Non-array row.
            'not-an-array',
        ];
        self::assertFalse(ConsumerStalledPredicate::hasStalledRow(
            $rows,
            60,
            self::NOW_EPOCH
        ));
    }

    public function testNegativeThresholdReturnsFalse(): void
    {
        // Defensive — a negative threshold is nonsensical. The
        // predicate treats it as "feature off" rather than crashing
        // (admin chrome must never go down because of a malformed
        // config row).
        $rows = [
            [
                'status'     => 'queued',
                'started_at' => self::epochToMysql(self::NOW_EPOCH - 9999),
            ],
        ];
        self::assertFalse(ConsumerStalledPredicate::hasStalledRow(
            $rows,
            -1,
            self::NOW_EPOCH
        ));
    }

    public function testZeroThresholdFiresOnAnyAgedQueuedRow(): void
    {
        // Threshold of 0 means "any queued row older than now is
        // stuck". A row started 1 second ago must trip it; a row
        // started exactly at NOW must not (strict `>` boundary
        // matches the 60s boundary test above).
        self::assertTrue(ConsumerStalledPredicate::hasStalledRow(
            [['status' => 'queued', 'started_at' => self::epochToMysql(self::NOW_EPOCH - 1)]],
            0,
            self::NOW_EPOCH
        ));
        self::assertFalse(ConsumerStalledPredicate::hasStalledRow(
            [['status' => 'queued', 'started_at' => self::epochToMysql(self::NOW_EPOCH)]],
            0,
            self::NOW_EPOCH
        ));
    }

    /**
     * Format a unix epoch as the bare MySQL DATETIME string Magento's
     * DB layer emits for the `ironcart_scan_run.started_at` column
     * (`YYYY-MM-DD HH:MM:SS`, no offset). The setUp() above pins
     * default_timezone to UTC so strtotime() interprets these bare
     * strings as UTC and matches the epoch back out.
     */
    private static function epochToMysql(int $epoch): string
    {
        return gmdate('Y-m-d H:i:s', $epoch);
    }
}
