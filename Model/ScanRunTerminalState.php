<?php

/**
 * IronCart_Scan — terminal-state invariant guard for ScanRun rows.
 *
 * Pure-PHP helper that pins the relationship between
 * `ironcart_scan_run.status` and `ironcart_scan_run.finished_at`. Every
 * row whose status is in {@see self::TERMINAL_STATUSES} MUST also have
 * `finished_at` populated; every non-terminal row MUST have
 * `finished_at` null. This is the contract the admin listing depends on
 * (#76) — a missing `finished` column for a `succeeded`/`failed` row is
 * a state-machine bug, not a render bug.
 *
 * The class lives here (not under Test/) so the consumer can use it as
 * a defense-in-depth runtime check right before any terminal save. That
 * way a future regression — e.g. someone re-ordering the consumer so
 * `setStatus(SUCCEEDED)` runs before `setFinishedAt(...)` and a thrown
 * exception in between leaves the row partially-terminal — fails loud
 * at the call site instead of silently shipping an empty `finished`
 * column to the admin grid.
 *
 * Magento-free by design: the constants are duplicated from
 * {@see \IronCart\Scan\Model\ScanRun::STATUS_*} as plain literals so
 * the helper can live under Test/Unit/Report in the CI unit cell, which
 * runs without `magento/framework` on the classpath (see .github/workflows/ci.yml).
 * The ScanRunTerminalStateTest pins both sides — any divergence between
 * ScanRun's STATUS_* constants and this class will fail the test.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Model;

use LogicException;

/**
 * Terminal-state invariant guard for the `ironcart_scan_run` table.
 */
final class ScanRunTerminalState
{
    public const STATUS_QUEUED    = 'queued';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';

    /**
     * Statuses that are terminal — rows in one of these states MUST
     * have a non-null `finished_at`. The order is the canonical write
     * order (`queued -> running -> succeeded|failed`) — `running` is
     * not terminal so it is not listed here.
     *
     * @var list<string>
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
    ];

    /**
     * Private constructor — this class is a constants-and-static-methods
     * holder, never instantiated. The constructor exists only to make
     * the intent explicit (and stop phpcs from auto-suggesting `final
     * class` removal because there's nothing instantiable).
     */
    private function __construct()
    {
    }

    /**
     * True when `$status` is one of `succeeded` or `failed`.
     */
    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Assert that `$status` and `$finishedAt` together describe a
     * legal `ironcart_scan_run` row.
     *
     * Two invariants are enforced:
     *
     *   1. Terminal status (`succeeded`/`failed`) requires a non-null,
     *      non-empty `$finishedAt`.
     *   2. Non-terminal status (`queued`/`running`) requires a null
     *      `$finishedAt` — a `running` row with `finished_at` already
     *      set indicates a partial save / re-entrant consumer.
     *
     * Throws {@see LogicException} on violation so the caller fails
     * fast. The consumer wraps its terminal `save()` calls with this
     * assertion so any regression in the write path becomes a loud
     * runtime error rather than a silent empty-grid-column bug.
     *
     * @throws LogicException When the (status, finished_at) tuple is illegal.
     */
    public static function assertConsistent(string $status, ?string $finishedAt): void
    {
        $finishedAtIsSet = is_string($finishedAt) && $finishedAt !== '';

        if (self::isTerminal($status)) {
            if (!$finishedAtIsSet) {
                throw new LogicException(sprintf(
                    'IronCart_Scan: ScanRun status "%s" is terminal but finished_at is %s. '
                    . 'Set finished_at before transitioning to a terminal status (#76).',
                    $status,
                    $finishedAt === null ? 'null' : 'empty'
                ));
            }
            return;
        }

        if ($finishedAtIsSet) {
            throw new LogicException(sprintf(
                'IronCart_Scan: ScanRun status "%s" is non-terminal but finished_at is set to "%s". '
                . 'Clear finished_at when transitioning a row out of a terminal status (#76).',
                $status,
                $finishedAt
            ));
        }
    }
}
