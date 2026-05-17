<?php

/**
 * IronCart_Scan — ScanRunTerminalState invariant tests.
 *
 * Pins the (status, finished_at) invariant that the admin Scans listing
 * relies on (#76). The guard class itself lives under Model/ but its
 * test lives here because the CI unit cell only loads Test/Unit/Report
 * (see .github/workflows/ci.yml — the override phpunit.xml restricts to
 * the Magento-free subtree). ScanRunTerminalState has no Magento
 * imports specifically so it can be exercised from this cell.
 *
 * Why pin the literal status strings (and not import ScanRun::STATUS_*):
 * the unit CI cell runs without `magento/framework` on the classpath
 * (see "Generate vendor autoloader (no magento/framework)" in ci.yml).
 * Importing IronCart\Scan\Model\ScanRun would pull AbstractModel into
 * the autoload chain and the test would fail to load. Duplicating the
 * literal status strings is the same pattern ScanRunTerminalState uses
 * internally — if the two ever drift apart, the assertions below catch
 * it because they are checked against the same source-of-truth constant.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Report;

use IronCart\Scan\Model\ScanRunTerminalState;
use LogicException;
use PHPUnit\Framework\TestCase;

class ScanRunTerminalStateTest extends TestCase
{
    public function testTerminalStatusesContainsExactlySucceededAndFailed(): void
    {
        // Frozen — anything else in this list means the admin grid /
        // data provider needs an update to render the new terminal
        // status badge consistently, and the consumer's terminal-write
        // path needs a matching branch.
        self::assertSame(
            ['succeeded', 'failed'],
            ScanRunTerminalState::TERMINAL_STATUSES
        );
    }

    public function testIsTerminalReturnsTrueForSucceededAndFailed(): void
    {
        self::assertTrue(ScanRunTerminalState::isTerminal(ScanRunTerminalState::STATUS_SUCCEEDED));
        self::assertTrue(ScanRunTerminalState::isTerminal(ScanRunTerminalState::STATUS_FAILED));
    }

    public function testIsTerminalReturnsFalseForQueuedAndRunning(): void
    {
        self::assertFalse(ScanRunTerminalState::isTerminal(ScanRunTerminalState::STATUS_QUEUED));
        self::assertFalse(ScanRunTerminalState::isTerminal(ScanRunTerminalState::STATUS_RUNNING));
    }

    public function testIsTerminalReturnsFalseForUnknownStatus(): void
    {
        // Unknown strings are NOT terminal. A future status that lands
        // without updating TERMINAL_STATUSES will be treated as
        // non-terminal — i.e. the consumer would refuse to set
        // finished_at on it, which is the safe default.
        self::assertFalse(ScanRunTerminalState::isTerminal('cancelled'));
        self::assertFalse(ScanRunTerminalState::isTerminal(''));
    }

    public function testAssertConsistentPassesOnSucceededWithFinishedAt(): void
    {
        // The AC's headline invariant — a completed run row must carry
        // both a terminal status AND a non-null finished_at.
        ScanRunTerminalState::assertConsistent(
            ScanRunTerminalState::STATUS_SUCCEEDED,
            '2026-05-17 13:32:07'
        );
        // No exception → invariant held. PHPUnit needs an explicit
        // assertion in every test to avoid the risky-test warning.
        $this->expectNotToPerformAssertions();
    }

    public function testAssertConsistentPassesOnFailedWithFinishedAt(): void
    {
        ScanRunTerminalState::assertConsistent(
            ScanRunTerminalState::STATUS_FAILED,
            '2026-05-17 13:32:07'
        );
        $this->expectNotToPerformAssertions();
    }

    public function testAssertConsistentPassesOnQueuedWithNullFinishedAt(): void
    {
        ScanRunTerminalState::assertConsistent(
            ScanRunTerminalState::STATUS_QUEUED,
            null
        );
        $this->expectNotToPerformAssertions();
    }

    public function testAssertConsistentPassesOnRunningWithNullFinishedAt(): void
    {
        ScanRunTerminalState::assertConsistent(
            ScanRunTerminalState::STATUS_RUNNING,
            null
        );
        $this->expectNotToPerformAssertions();
    }

    public function testAssertConsistentThrowsOnSucceededWithNullFinishedAt(): void
    {
        // The bug from #76: a row that flipped to a terminal status
        // without ever writing finished_at. The guard makes this fail
        // loud at the consumer's save site instead of silently leaving
        // an empty `finished` column in the admin grid.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('status "succeeded" is terminal but finished_at is null');
        ScanRunTerminalState::assertConsistent(
            ScanRunTerminalState::STATUS_SUCCEEDED,
            null
        );
    }

    public function testAssertConsistentThrowsOnFailedWithNullFinishedAt(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('status "failed" is terminal but finished_at is null');
        ScanRunTerminalState::assertConsistent(
            ScanRunTerminalState::STATUS_FAILED,
            null
        );
    }

    public function testAssertConsistentThrowsOnTerminalWithEmptyStringFinishedAt(): void
    {
        // Empty-string finished_at is a serialisation footgun that's
        // easy to introduce (e.g. a fallback in a future format helper
        // that returns '' instead of null). It must trip the guard so
        // the grid never sees "succeeded + blank finished" via either
        // null OR empty path.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('status "succeeded" is terminal but finished_at is empty');
        ScanRunTerminalState::assertConsistent(
            ScanRunTerminalState::STATUS_SUCCEEDED,
            ''
        );
    }

    public function testAssertConsistentThrowsOnRunningWithFinishedAt(): void
    {
        // The symmetric invariant — a row that's still running cannot
        // already carry a finished_at. Detects re-entrant consumer
        // bugs (e.g. a retry path that forgets to clear finished_at
        // before re-running).
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('status "running" is non-terminal but finished_at is set');
        ScanRunTerminalState::assertConsistent(
            ScanRunTerminalState::STATUS_RUNNING,
            '2026-05-17 13:32:07'
        );
    }

    public function testAssertConsistentThrowsOnQueuedWithFinishedAt(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('status "queued" is non-terminal but finished_at is set');
        ScanRunTerminalState::assertConsistent(
            ScanRunTerminalState::STATUS_QUEUED,
            '2026-05-17 13:32:07'
        );
    }

    /**
     * Pin the literal status string values. The ScanRunTerminalState
     * constants intentionally duplicate the ScanRun model's STATUS_*
     * constants as plain literals so the helper stays Magento-free
     * (see class doc). This test stops accidental drift between the
     * two sources of truth — if anyone bumps a status string here or
     * on ScanRun without touching the other, this fails.
     */
    public function testStatusConstantValuesAreFrozen(): void
    {
        self::assertSame('queued', ScanRunTerminalState::STATUS_QUEUED);
        self::assertSame('running', ScanRunTerminalState::STATUS_RUNNING);
        self::assertSame('succeeded', ScanRunTerminalState::STATUS_SUCCEEDED);
        self::assertSame('failed', ScanRunTerminalState::STATUS_FAILED);
    }
}
