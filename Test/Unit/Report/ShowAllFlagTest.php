<?php

/**
 * IronCart_Scan — coverage for the "show all severities" truthy rule.
 *
 * Lives under Test/Unit/Report (alongside AdminUiShapeTest) because
 * that's the only subdirectory the v0 unit-CI cell loads — the cell
 * strips magento/framework before composer install so any test that
 * touches Magento types is unreachable in the unit job. The helper
 * under test ({@see ShowAllFlag}) was deliberately authored without
 * any Magento dependencies for exactly this reason.
 *
 * What this test guards against:
 *
 *   - Regressing the truthy-rule shared by the data provider (reads
 *     the flag from session) and the detail-view button (reads from
 *     URL) — both delegate to `ShowAllFlag::isTruthy`, so a single
 *     interpretation drift would silently desync the button label
 *     from the actual grid filter.
 *
 *   - Re-introducing the bug fixed by issue #97. Previously
 *     `ScanFindingDataProvider::isShowAllRequested()` read directly
 *     from the AJAX request scope, where the page URL's `showAll`
 *     param is never present. The shape test in AdminUiShapeTest
 *     only validated the XML; nothing exercised the flag-read path.
 *     This test exercises the parser end-to-end on the same data
 *     shapes the request / session layers feed it.
 *
 *   - The session-bucket key constant — pinned here so renaming
 *     `ShowAllFlag::SESSION_KEY` becomes a deliberate, two-file
 *     change (helper + this test) rather than a silent breakage of
 *     the controller↔provider handshake.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Report;

use IronCart\Scan\Ui\DataProvider\ShowAllFlag;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Ui\DataProvider\ShowAllFlag
 */
class ShowAllFlagTest extends TestCase
{
    /**
     * The session key is the contract between the detail-view
     * controller (writer) and the findings data provider (reader).
     * If anyone renames the constant, this assertion forces them to
     * touch both files.
     */
    public function testSessionKeyIsStable(): void
    {
        self::assertSame(
            'ironcart_scan_show_all_severities',
            ShowAllFlag::SESSION_KEY,
            'session key is part of the controller↔provider handshake; renames must be deliberate'
        );
    }

    /**
     * The default (no request param, button never clicked) must
     * leave the critical-only filter active.
     */
    public function testNullValueIsNotTruthy(): void
    {
        self::assertFalse(ShowAllFlag::isTruthy(null));
    }

    /**
     * `RequestInterface::getParam(...)` returns `''` when the param
     * key is present but blank (e.g. `?showAll=`). Treat as off so
     * a copy-pasted URL with a dangling key doesn't accidentally
     * lift the filter.
     */
    public function testEmptyStringIsNotTruthy(): void
    {
        self::assertFalse(ShowAllFlag::isTruthy(''));
    }

    /**
     * Explicit off-via-URL: `?showAll=0`. Magento's request layer
     * surfaces this as the string `'0'`, not the int 0, so we test
     * both shapes.
     */
    public function testStringZeroIsNotTruthy(): void
    {
        self::assertFalse(ShowAllFlag::isTruthy('0'));
    }

    public function testIntegerZeroIsNotTruthy(): void
    {
        self::assertFalse(ShowAllFlag::isTruthy(0));
    }

    /**
     * The session bucket stores the parsed bool. When the most
     * recent page render had no showAll param, the controller
     * writes `false`. Subsequent grid AJAX must respect that.
     */
    public function testBooleanFalseIsNotTruthy(): void
    {
        self::assertFalse(ShowAllFlag::isTruthy(false));
    }

    /**
     * Happy path: `?showAll=1`. This is what the button itself
     * appends to the URL when the user opts into the lifted view.
     */
    public function testStringOneIsTruthy(): void
    {
        self::assertTrue(ShowAllFlag::isTruthy('1'));
    }

    public function testIntegerOneIsTruthy(): void
    {
        self::assertTrue(ShowAllFlag::isTruthy(1));
    }

    /**
     * The controller writes the parsed bool back into the session,
     * so the reader (data provider) sees `true` directly. That
     * second hop must also be truthy.
     */
    public function testBooleanTrueIsTruthy(): void
    {
        self::assertTrue(ShowAllFlag::isTruthy(true));
    }

    /**
     * The truthy-rule is permissive on intentional positive values.
     * Anyone hand-crafting a URL like `?showAll=yes` should get the
     * expected behaviour — matches how Magento's own admin grid
     * filters treat arbitrary truthy strings.
     */
    public function testArbitraryNonEmptyStringIsTruthy(): void
    {
        self::assertTrue(ShowAllFlag::isTruthy('yes'));
        self::assertTrue(ShowAllFlag::isTruthy('on'));
        self::assertTrue(ShowAllFlag::isTruthy('true'));
    }

    /**
     * End-to-end: simulate the issue #97 root cause to prove this
     * helper is what the fix hinges on.
     *
     * Page render: URL carries `?showAll=1`. Controller calls
     * `ShowAllFlag::isTruthy('1')` → true, writes that bool to the
     * session.
     *
     * Grid AJAX: the XHR has no query string. Data provider reads
     * the session bucket directly (a `true` bool, NOT a string)
     * and calls `ShowAllFlag::isTruthy(true)` → must still be true.
     *
     * If anyone reverts the data provider to read from the AJAX
     * request scope, that call would parse `null` (no param
     * present) and return false — which is the exact regression
     * #97 caught in production.
     */
    public function testControllerToProviderHandshakeYieldsTruthyOnLiftedRequest(): void
    {
        // Page-render scope: parse the URL param.
        $writerSawTruthy = ShowAllFlag::isTruthy('1');
        self::assertTrue($writerSawTruthy, 'controller must see URL param as truthy');

        // AJAX scope: read the bucketed bool. Must still be truthy.
        $readerSawTruthy = ShowAllFlag::isTruthy($writerSawTruthy);
        self::assertTrue(
            $readerSawTruthy,
            'data provider must see the bucketed bool as truthy — this is the issue #97 round-trip'
        );
    }

    /**
     * Inverse of the above — a fresh navigation without `?showAll`
     * must overwrite any previously-stashed `true` with `false`.
     * Guards the "lifted state must not leak across navigations"
     * AC from issue #97.
     */
    public function testControllerToProviderHandshakeYieldsFalsyOnFreshNavigation(): void
    {
        // Page-render scope: no URL param present.
        $writerSawTruthy = ShowAllFlag::isTruthy(null);
        self::assertFalse($writerSawTruthy, 'controller must see missing param as falsy');

        // AJAX scope: reads the bucketed `false`.
        $readerSawTruthy = ShowAllFlag::isTruthy($writerSawTruthy);
        self::assertFalse(
            $readerSawTruthy,
            'data provider must see the bucketed false as falsy — fresh navigations override stale lifted state'
        );
    }
}
