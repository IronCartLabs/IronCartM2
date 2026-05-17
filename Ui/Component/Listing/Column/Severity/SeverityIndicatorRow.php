<?php

/**
 * IronCart_Scan — pure-PHP renderer for the run-listing severity column.
 *
 * Emits a horizontal 5-slot row of coloured circle indicators (one per
 * severity in {@see \IronCart\Scan\Report\Severity::ALL}) with the
 * numeric count rendered inside each circle. Every severity always
 * occupies a slot — zero-count severities render as a muted/empty
 * circle so rows stay vertically aligned in the admin grid.
 *
 * Lives in its own Magento-free namespace so the markup logic can be
 * unit-tested under {@see \IronCart\Scan\Test\Unit\Report} (the only
 * test subtree the v0 unit-CI cell loads — magento/framework is
 * stripped before composer install). {@see \IronCart\Scan\Ui\Component\Listing\Column\SeverityTotals}
 * is a thin wrapper that calls into this helper.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Ui\Component\Listing\Column\Severity;

use IronCart\Scan\Report\Severity;

/**
 * Renders the per-row severity-totals cell as a horizontal indicator row.
 *
 * Markup is intentionally minimal — five sibling spans with predictable
 * class names so the accompanying stylesheet
 * (`view/adminhtml/web/css/run-listing-severity.css`) can drive sizing,
 * colour, and alignment without inline-style sprawl.
 */
final class SeverityIndicatorRow
{
    /**
     * Human-readable severity labels used in each circle's hover/tooltip
     * (`title=`). Keep the casing aligned with the rest of the admin UI
     * (Pascal-case capitalised words).
     *
     * @var array<string,string>
     */
    private const LABEL = [
        Severity::CRITICAL => 'Critical',
        Severity::HIGH     => 'High',
        Severity::MEDIUM   => 'Medium',
        Severity::LOW      => 'Low',
        Severity::INFO     => 'Info',
    ];

    /**
     * Severity → BEM-style modifier suffix used in the indicator class
     * name. The stylesheet binds palette colours to these modifiers, so
     * the suffix vocabulary is load-bearing: changing one here requires
     * updating the CSS file in lockstep.
     *
     * @var array<string,string>
     */
    private const MODIFIER = [
        Severity::CRITICAL => 'critical',
        Severity::HIGH     => 'high',
        Severity::MEDIUM   => 'medium',
        Severity::LOW      => 'low',
        Severity::INFO     => 'info',
    ];

    private function __construct()
    {
    }

    /**
     * Build the HTML for a single row's severity cell.
     *
     * @param array<string,mixed> $totals severity → count map. Unknown
     *                                    keys are ignored; missing
     *                                    severities default to 0.
     */
    public static function render(array $totals): string
    {
        $slots = [];
        foreach (Severity::ALL as $severity) {
            $count = (int)($totals[$severity] ?? 0);
            $slots[] = self::renderSlot($severity, $count);
        }

        return sprintf(
            '<span class="ironcart-severity-row" role="group" aria-label="Severity totals">%s</span>',
            implode('', $slots)
        );
    }

    /**
     * Render one severity slot. Empty severities still produce a slot
     * (with the `is-empty` modifier) so the cell width stays constant
     * across rows and columns align by eye.
     */
    private static function renderSlot(string $severity, int $count): string
    {
        $modifier = self::MODIFIER[$severity] ?? 'unknown';
        $label = self::LABEL[$severity] ?? ucfirst($severity);
        $tooltip = sprintf('%s: %d', $label, $count);
        $emptyClass = $count > 0 ? '' : ' ironcart-severity-circle--empty';

        return sprintf(
            '<span class="ironcart-severity-slot">'
                . '<span class="ironcart-severity-circle ironcart-severity-circle--%s%s" '
                    . 'title="%s" aria-label="%s">'
                    . '<span class="ironcart-severity-count">%d</span>'
                . '</span>'
            . '</span>',
            htmlspecialchars($modifier, ENT_QUOTES, 'UTF-8'),
            $emptyClass,
            htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8'),
            $count
        );
    }
}
