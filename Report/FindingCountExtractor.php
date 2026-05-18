<?php

/**
 * IronCart_Scan — pure pipeline to derive the scalar `finding_count`
 * for an `ironcart_scan_run` row out of its `summary_json` blob.
 *
 * Lives under `Report/` (the Magento-free slice of the module) so the
 * unit-CI cell can exercise it without booting Magento. Used by both
 * the data-patch backfill (Setup\Patch\Data\BackfillFindingCounts) and
 * any future read-side path that needs to coerce historic JSON shapes
 * to the same int as the consumer writes today.
 *
 * Contract: malformed JSON, missing `finding_count` key, non-numeric
 * values, and negative numbers all collapse to null (so the row stays
 * out of any numeric-range filter window rather than poisoning it with
 * a sentinel like 0 that an admin could legitimately match).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Report;

class FindingCountExtractor
{
    /**
     * Derive the scalar finding_count from a summary_json blob.
     *
     * Returns null when the input is null/empty/malformed/missing-key,
     * or when the value is not a non-negative integer. Otherwise
     * returns the int.
     *
     * @param string|null $summaryJson Raw JSON string from
     *                                 ironcart_scan_run.summary_json
     */
    public static function fromSummaryJson(?string $summaryJson): ?int
    {
        if ($summaryJson === null || $summaryJson === '') {
            return null;
        }

        // Use json_decode here rather than Magento\Framework\Serialize
        // so this helper stays pure (no DI, no Magento types) and the
        // unit-CI cell — which strips magento/framework — can exercise
        // it. The behaviour is byte-identical for the JSON shape we
        // emit ourselves in ScanRunConsumer::runScan().
        $decoded = json_decode($summaryJson, true);
        if (!is_array($decoded)) {
            return null;
        }

        if (!array_key_exists('finding_count', $decoded)) {
            // Older rows (or failed-run rows with `error` envelope)
            // never carried a finding_count. Fall through to derive
            // from totals if possible so backfill can still seed them.
            return self::sumTotals($decoded['totals'] ?? null);
        }

        $value = $decoded['finding_count'];
        if (!is_numeric($value)) {
            return null;
        }
        $int = (int)$value;
        return $int < 0 ? null : $int;
    }

    /**
     * Sum a `totals` map ({severity → int}). Returns null if the input
     * is not an array, or if every entry is non-numeric.
     *
     * @param mixed $totals
     */
    private static function sumTotals(mixed $totals): ?int
    {
        if (!is_array($totals) || $totals === []) {
            return null;
        }

        $sum = 0;
        $sawAny = false;
        foreach ($totals as $value) {
            if (!is_numeric($value)) {
                continue;
            }
            $intValue = (int)$value;
            if ($intValue < 0) {
                continue;
            }
            $sum += $intValue;
            $sawAny = true;
        }
        return $sawAny ? $sum : null;
    }
}
