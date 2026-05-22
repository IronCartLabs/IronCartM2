<?php

/**
 * IronCart_Scan — stuck-queue predicate for the Run-Scan-Now async pipeline.
 *
 * Pure-PHP helper that decides whether the operator should be warned that
 * `ironcartScanRunConsumer` is not being drained. The rule, from issue
 * #92: any `ironcart_scan_run` row whose `status` is exactly `queued` and
 * whose `started_at` is older than `thresholdSeconds` indicates a queued
 * run that no consumer has picked up. The same threshold acts as both the
 * "is the consumer running at all" probe and the "clear noise from
 * just-enqueued rows" filter (a fresh row will always be `queued` for a
 * brief window while the consumer is still picking it up).
 *
 * Magento-free by design, same trick as
 * {@see \IronCart\Scan\Model\ScanRunTerminalState}: the status string is
 * duplicated as a literal so the helper can live under Test/Unit/Report
 * in the CI unit cell, which runs without `magento/framework` on the
 * classpath (see `.github/workflows/ci.yml`). The companion
 * {@see ConsumerStalledMessage} class is the Magento-side adapter that
 * pulls rows out of the DB and feeds them through this predicate.
 *
 * Inputs are deliberately shaped as plain arrays of
 * `['status' => ..., 'started_at' => ...]` rather than ScanRun objects
 * so the unit test can exercise the boundary without instantiating a
 * Magento AbstractModel.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Model\Notification;

/**
 * Stuck-queue predicate.
 *
 * The predicate is single-rule by design — it returns true the moment
 * any row qualifies. The notice itself does not surface a count or per-
 * row detail: the operator's next action is "start the consumer," which
 * drains every queued row regardless of how many are stuck.
 */
final class ConsumerStalledPredicate
{
    /**
     * Status literal mirrored from {@see \IronCart\Scan\Model\ScanRun::STATUS_QUEUED}.
     * Kept as a string literal so this class has no Magento autoload
     * dependency (see class docblock).
     */
    public const STATUS_QUEUED = 'queued';

    /**
     * Default threshold in seconds, mirrored to the `<default>` block in
     * `etc/config.xml` under
     * `ironcart_scan/runtime/consumer_alert_threshold_seconds`. 60s is
     * long enough that a freshly-enqueued row has time to flip to
     * `running` under the module-owned `ironcart_scan_consumer_drain`
     * cron job (every-minute schedule, see
     * IronCartLabs/IronCartM2#143 for the drain lifecycle and #155 for
     * the in-handler lock that prevents core's `consumers_runner` from
     * racing the same consumer), but short enough that the notice
     * fires within one admin-refresh cycle on a truly stuck install
     * (Magento's own cron not running at all).
     */
    public const DEFAULT_THRESHOLD_SECONDS = 60;

    /**
     * Private constructor — this class is a constants-and-static-methods
     * holder, never instantiated. Mirrors
     * {@see \IronCart\Scan\Model\ScanRunTerminalState}.
     */
    private function __construct()
    {
    }

    /**
     * True when at least one row in `$rows` represents a queued scan that
     * has been waiting longer than `$thresholdSeconds`.
     *
     * Rows are expected to be associative arrays with the shape
     * `['status' => string, 'started_at' => string|int|null]`. The
     * `started_at` value may be:
     *
     *   - a MySQL DATETIME / TIMESTAMP string (`'2026-05-18 02:12:37'`),
     *     which is what the `ironcart_scan_run` schema in `db_schema.xml`
     *     stores;
     *   - an int unix timestamp;
     *   - null (treated as "row has no started_at yet" — never stuck).
     *
     * Rows whose `status` is not `queued` are silently skipped. Anything
     * malformed (missing keys, unparseable timestamp, negative
     * threshold) is silently treated as non-stuck so the predicate is
     * total — Magento's notice-list runs on every admin page render and
     * we never want it to raise.
     *
     * @param iterable<int,array<string,mixed>> $rows
     * @param int                               $thresholdSeconds  Default {@see DEFAULT_THRESHOLD_SECONDS}.
     * @param int                               $nowEpochSeconds   Unix epoch for the comparison; pass time() in production.
     */
    public static function hasStalledRow(
        iterable $rows,
        int $thresholdSeconds,
        int $nowEpochSeconds
    ): bool {
        if ($thresholdSeconds < 0) {
            // Negative thresholds make no sense; treat as "feature off"
            // rather than crashing the admin render path.
            return false;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!isset($row['status']) || !is_string($row['status'])) {
                continue;
            }
            if ($row['status'] !== self::STATUS_QUEUED) {
                continue;
            }
            $startedAtEpoch = self::parseStartedAt($row['started_at'] ?? null);
            if ($startedAtEpoch === null) {
                continue;
            }
            $ageSeconds = $nowEpochSeconds - $startedAtEpoch;
            // Strict `>` — at exactly the threshold the row is "just on
            // the line" and we accept it as not-yet-stuck. The unit test
            // pins both sides of the boundary.
            if ($ageSeconds > $thresholdSeconds) {
                return true;
            }
        }

        return false;
    }

    /**
     * Coerce a stored `started_at` value into a unix epoch. Returns null
     * when the input is unusable so the caller skips the row rather
     * than crashing.
     *
     * @param mixed $value
     */
    private static function parseStartedAt($value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        // MySQL DATETIME / TIMESTAMP strings ('YYYY-MM-DD HH:MM:SS')
        // parse cleanly via strtotime under any timezone — they are
        // emitted by Magento's DB layer in the connection's session
        // timezone, which the admin process also runs in. Worst case
        // (wildly wrong server timezone), the worst symptom is a
        // false-negative (we treat a stuck row as not-stuck for a
        // few minutes longer than the threshold), which is acceptable
        // for an operator-detection notice.
        $epoch = strtotime($value);
        if ($epoch === false) {
            return null;
        }
        return $epoch;
    }
}
