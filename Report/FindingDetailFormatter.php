<?php

/**
 * IronCart_Scan — admin grid `detail` column formatter.
 *
 * Pure pipeline that turns a finding's `evidence` array plus optional
 * `remediation_url` into the one-line string admins see in the `Detail`
 * column of the scan-detail grid
 * (`ironcartscan/scans/view/id/<id>` → `ironcart_scan_finding.detail`).
 *
 * Why this lives in Report/ and not Model/:
 *   - The unit-CI cell only loads Test/Unit/Report (see
 *     .github/workflows/ci.yml — magento/framework is stripped from
 *     composer.json before composer install, so any test that touches
 *     a Magento type fails at autoload). Putting the formatter under
 *     Report/ keeps it on phpstan's analysed-paths list and lets
 *     FindingDetailFormatterTest run in the Magento-free unit slice
 *     alongside ReportBuilderTest et al.
 *   - The formatter is logically part of the report-shape vocabulary
 *     (Severity, ReportBuilder, ReportRenderer) — it's the human
 *     rendering of an evidence blob, not part of the message-queue
 *     consumer's persistence concern. ScanRunConsumer just calls it.
 *
 * Output contract:
 *   - Returns `null` when both evidence and remediation URL are empty.
 *     Callers persist NULL in that case rather than synthesising an
 *     empty string (per AC, existing NULL rows must continue to render
 *     as empty without migration; new "nothing to say" rows match
 *     that shape).
 *   - When evidence is a non-empty array, flattens to
 *     `key=value, key=value` ordered by the original key order.
 *     Scalar values render natively; non-scalar values (nested arrays,
 *     objects) JSON-encode to stay on a single line.
 *   - When evidence is a scalar string/int/float/bool, renders that
 *     value directly.
 *   - When a non-empty remediation URL is present, appends
 *     ` — see <url>`. Em-dash is intentional — the data provider
 *     truncates at 240 chars and the em-dash gives admins a clear
 *     visual break between the evidence summary and the link without
 *     burning bytes on punctuation that would render the same.
 *
 * Truncation is owned by
 * {@see \IronCart\Scan\Ui\DataProvider\ScanFindingDataProvider::truncate()}
 * — keep that single truncation budget; do not double-truncate here.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Report;

/**
 * Formats a finding's `evidence` + `remediation_url` into a single-line
 * `detail` string for admin-grid rendering. Stateless; safe to inject
 * as a Magento singleton.
 */
class FindingDetailFormatter
{
    /**
     * Visual break between the evidence summary and the remediation
     * URL. See class docblock for why em-dash.
     */
    private const URL_SEPARATOR = ' — see ';

    /**
     * Build the `detail` string for a single finding, or `null` if
     * there is nothing meaningful to render.
     *
     * @param mixed  $evidence       The check's evidence payload. Arrays
     *                               are flattened; scalars render
     *                               natively; null / [] / '' are
     *                               treated as "no evidence".
     * @param string $remediationUrl Optional URL appended after the
     *                               evidence summary. Empty string =
     *                               omit.
     */
    public function format(mixed $evidence, string $remediationUrl): ?string
    {
        $evidenceString = $this->flattenEvidence($evidence);
        $trimmedUrl = trim($remediationUrl);

        if ($evidenceString === '' && $trimmedUrl === '') {
            return null;
        }

        if ($evidenceString === '') {
            return 'see ' . $trimmedUrl;
        }

        if ($trimmedUrl === '') {
            return $evidenceString;
        }

        return $evidenceString . self::URL_SEPARATOR . $trimmedUrl;
    }

    /**
     * Render the evidence payload as a single line, or an empty string
     * if the payload is semantically empty.
     */
    private function flattenEvidence(mixed $evidence): string
    {
        if ($evidence === null) {
            return '';
        }

        if (is_string($evidence)) {
            return trim($evidence);
        }

        if (is_scalar($evidence)) {
            return $this->scalarToString($evidence);
        }

        if (is_array($evidence)) {
            if ($evidence === []) {
                return '';
            }
            return $this->flattenArray($evidence);
        }

        // Objects / resources / closures — defensive fallback. We
        // intentionally do not JSON-encode here because objects with
        // private state would leak structure into admin output without
        // an authorial decision from the check class.
        return '';
    }

    /**
     * Flatten an associative or list-shaped array into
     * `key=value, key=value`. List-shaped arrays (numeric keys 0..n)
     * render as `[v1, v2, v3]` so admins can tell at a glance that
     * the value was a list, not a single scalar.
     *
     * @param array<array-key,mixed> $evidence
     */
    private function flattenArray(array $evidence): string
    {
        if (array_is_list($evidence)) {
            $parts = [];
            foreach ($evidence as $value) {
                $parts[] = $this->valueToString($value);
            }
            return '[' . implode(', ', $parts) . ']';
        }

        $parts = [];
        foreach ($evidence as $key => $value) {
            $parts[] = ((string)$key) . '=' . $this->valueToString($value);
        }
        return implode(', ', $parts);
    }

    /**
     * Render a single evidence-array value. Scalars use their native
     * string form; nested arrays and objects JSON-encode so the line
     * stays single-line and parseable.
     */
    private function valueToString(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return $this->scalarToString($value);
        }

        // Nested array or object. JSON_UNESCAPED_SLASHES keeps URL
        // values readable; JSON_UNESCAPED_UNICODE keeps non-ASCII
        // store names / paths readable rather than \uXXXX-escaped.
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        return $encoded === false ? '' : $encoded;
    }

    /**
     * String-cast a scalar with bool-friendly output (PHP's native
     * (string)true === '1' / (string)false === '' which both render
     * confusingly in a `key=value` line).
     */
    private function scalarToString(bool|int|float|string $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string)$value;
    }
}
