<?php

/**
 * IronCart_Scan — pure helper that parses a "show all severities" flag
 * out of an arbitrary request-param value.
 *
 * Extracted from {@see ScanFindingDataProvider} and
 * {@see \IronCart\Scan\Ui\Component\Control\ShowAllSeveritiesButton} so
 * (a) the same truthy-rule is shared by every reader of the flag, and
 * (b) the rule itself can be unit-tested without booting the Magento
 * framework. The v0 unit-CI cell strips magento/framework before
 * composer install (see .github/workflows/ci.yml), so any test that
 * touches RequestInterface is unreachable in the unit job — this
 * helper deliberately has no Magento types in its signature.
 *
 * Session-bucket key is colocated here so the controller (writer) and
 * the data provider (reader) cannot drift apart.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Ui\DataProvider;

/**
 * @internal Module-private helper. Not part of the public API surface.
 */
final class ShowAllFlag
{
    /**
     * Backend-session key under which the per-page-render flag value is
     * stashed by the detail-view controller for the grid's AJAX
     * data-provider request to read. The grid's `mui/index/render`
     * XHR does NOT receive the parent page URL's query string (see
     * issue #97 root cause), so reading from the request directly in
     * the AJAX scope returns false every time.
     *
     * The session bucket is overwritten on every page render — truthy
     * URLs write `true`, everything else writes `false`. That makes
     * the most recent page render authoritative and prevents a lifted
     * filter from leaking across navigations.
     */
    public const SESSION_KEY = 'ironcart_scan_show_all_severities';

    /**
     * Private constructor — this class is a namespace for static
     * helpers, never instantiated. Magento's `final + private ctor`
     * pseudo-enum pattern is already documented as PHPCS-excluded in
     * .github/workflows/ci.yml, so the linter won't complain.
     */
    private function __construct()
    {
    }

    /**
     * Returns true when the supplied request-param value should be
     * interpreted as "show all severities" / "lift the critical-only
     * filter for this page render".
     *
     * The rule is deliberately strict: `null`, empty string, the
     * literal string `'0'`, the integer `0`, and boolean `false` all
     * mean "filter active". Everything else (`'1'`, `1`, `true`,
     * `'yes'`, etc.) means "filter lifted". This matches Magento's
     * convention for boolean-ish URL params and the v1 button which
     * appends `showAll=1` to the route.
     *
     * @param mixed $value
     */
    public static function isTruthy($value): bool
    {
        if ($value === null || $value === false) {
            return false;
        }
        if ($value === '' || $value === '0' || $value === 0) {
            return false;
        }
        return true;
    }
}
