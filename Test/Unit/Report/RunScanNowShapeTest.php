<?php

/**
 * IronCart_Scan — "Run scan now" + status endpoint structural pin.
 *
 * Lives under Test/Unit/Report for the same reason
 * {@see AdminUiShapeTest} and {@see QueueWiringShapeTest} do — it is
 * the only testsuite the unit-CI cell loads (see
 * .github/workflows/ci.yml). The Magento-free unit slice cannot
 * instantiate the controller / button classes (Magento\Backend\App\Action,
 * Magento\Framework\UrlInterface and friends are not on the classpath),
 * so this test exercises the load-bearing wiring by:
 *
 *   1. Parsing the listing XML and asserting the `run_scan_now` button
 *      entry points at the RunScanNowButton class.
 *   2. Parsing the PHP source of each controller for the ACL constant +
 *      the marker interface (HttpPostActionInterface / HttpGetActionInterface)
 *      that gates Magento's CSRF + method enforcement.
 *
 * What this test does NOT do:
 *   - Boot Magento or invoke the controllers.
 *   - Verify the JS module behaviour (smoke is the integration job's
 *     responsibility — issue #29 visual smoke AC).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Report;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * @coversNothing
 */
class RunScanNowShapeTest extends TestCase
{
    private const MODULE_ROOT = __DIR__ . '/../../..';
    private const RUN_LISTING = self::MODULE_ROOT . '/view/adminhtml/ui_component/ironcartscan_run_listing.xml';
    private const RUN_CONTROLLER = self::MODULE_ROOT . '/Controller/Adminhtml/Scans/Run.php';
    private const STATUS_CONTROLLER = self::MODULE_ROOT . '/Controller/Adminhtml/Scans/Status.php';
    private const BUTTON_CLASS = self::MODULE_ROOT . '/Ui/Component/Control/RunScanNowButton.php';
    private const JS_MODULE = self::MODULE_ROOT . '/view/adminhtml/web/js/run-scan-now.js';
    private const JS_INIT_SHIM = self::MODULE_ROOT . '/view/adminhtml/web/js/run-scan-now-init.js';
    private const CSP_WHITELIST = self::MODULE_ROOT . '/etc/csp_whitelist.xml';
    private const ACL = self::MODULE_ROOT . '/etc/acl.xml';

    public function testRunListingDeclaresRunScanNowButton(): void
    {
        self::assertFileExists(self::RUN_LISTING);
        $xml = simplexml_load_file(self::RUN_LISTING);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $buttons = [];
        foreach ($xml->argument as $arg) {
            if ((string)$arg['name'] !== 'data') {
                continue;
            }
            foreach ($arg->item as $item) {
                if ((string)$item['name'] !== 'buttons') {
                    continue;
                }
                foreach ($item->item as $btn) {
                    $buttons[(string)$btn['name']] = trim((string)$btn);
                }
            }
        }

        self::assertArrayHasKey(
            'run_scan_now',
            $buttons,
            'run-listing must declare a run_scan_now button'
        );
        self::assertSame(
            'IronCart\\Scan\\Ui\\Component\\Control\\RunScanNowButton',
            $buttons['run_scan_now'],
            'run_scan_now button must bind to RunScanNowButton'
        );
    }

    public function testAclDeclaresRunResourceAsChildOfView(): void
    {
        // The Run controller gates on `IronCart_Scan::run`. Distinct from
        // the read-only `::view` so an admin can be granted listing
        // access without enqueue rights.
        self::assertFileExists(self::ACL);
        $xml = simplexml_load_file(self::ACL);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        // Locate IronCart_Scan::scan parent, then assert both `view` and
        // `run` are declared underneath it.
        $parent = null;
        foreach ($xml->acl->resources->resource->resource as $candidate) {
            if ((string)$candidate['id'] === 'IronCart_Scan::scan') {
                $parent = $candidate;
                break;
            }
        }
        self::assertNotNull($parent, 'etc/acl.xml must declare IronCart_Scan::scan');

        $children = [];
        foreach ($parent->resource as $child) {
            $children[] = (string)$child['id'];
        }
        self::assertContains('IronCart_Scan::view', $children);
        self::assertContains('IronCart_Scan::run', $children);
    }

    public function testRunControllerImplementsHttpPostInterfaceAndGatesOnRunAcl(): void
    {
        $source = file_get_contents(self::RUN_CONTROLLER);
        self::assertIsString($source, 'Run controller source must be readable');

        self::assertMatchesRegularExpression(
            '/implements\s+HttpPostActionInterface/',
            $source,
            'Run controller must implement HttpPostActionInterface (Magento enforces form_key + method)'
        );
        self::assertMatchesRegularExpression(
            "/ADMIN_RESOURCE\s*=\s*'IronCart_Scan::run'/",
            $source,
            'Run controller must gate on IronCart_Scan::run'
        );
    }

    public function testStatusControllerImplementsHttpGetInterfaceAndGatesOnViewAcl(): void
    {
        $source = file_get_contents(self::STATUS_CONTROLLER);
        self::assertIsString($source, 'Status controller source must be readable');

        self::assertMatchesRegularExpression(
            '/implements\s+HttpGetActionInterface/',
            $source,
            'Status controller must implement HttpGetActionInterface (read-only)'
        );
        self::assertMatchesRegularExpression(
            "/ADMIN_RESOURCE\s*=\s*'IronCart_Scan::view'/",
            $source,
            'Status controller must gate on IronCart_Scan::view (polling is a read)'
        );
    }

    public function testRunControllerNeverCarriesAdminUsername(): void
    {
        // Triggered-by guard: the publisher receives `admin:<id>` only.
        // If anyone introduces `getUserName()` / `getUsername()` in the
        // Run controller we want this to fail loudly — admin usernames
        // would land in `ironcart_scan_run.triggered_by` and be visible
        // to anyone with `::view`.
        $source = file_get_contents(self::RUN_CONTROLLER);
        self::assertIsString($source);

        self::assertDoesNotMatchRegularExpression(
            '/getUser(?:n|N)ame\s*\(/',
            $source,
            'Run controller must not read admin username (PII guard)'
        );
        self::assertMatchesRegularExpression(
            "/'admin:'\s*\.\s*\\\$userId/",
            $source,
            'Run controller must build triggered_by as "admin:<id>"'
        );
    }

    /**
     * Declarative-init contract (issue #85, EQP CSP refactor).
     *
     * The button provider now emits a `data_attribute` => `mage-init`
     * JSON payload instead of an `on_click` `require([...], ...)`
     * inline JS string. Magento's toolbar template renders
     * `data_attribute` entries as `data-<name>="<value>"` attributes,
     * and the `mage/apply` bootstrap resolves the module on DOM ready.
     *
     * This test pins:
     *   - No `on_click` inline JS surface (load-bearing for EQP CSP).
     *   - `data_attribute` array is present with a `mage-init` key.
     *   - The init module id points at the run-scan-now-init shim.
     *   - The same URL constants the route table relies on are still
     *     declared on the class.
     */
    public function testButtonProviderEmitsDeclarativeMageInitNotInlineJs(): void
    {
        $source = file_get_contents(self::BUTTON_CLASS);
        self::assertIsString($source);

        // No `on_click` key in the returned array — that was the
        // inline-JS surface flagged by the EQP CSP review.
        self::assertDoesNotMatchRegularExpression(
            "/'on_click'\s*=>/",
            $source,
            'RunScanNowButton must not emit an on_click inline JS handler (EQP CSP audit item 29)'
        );
        // And the require()-from-PHP string-build pattern must be gone.
        self::assertDoesNotMatchRegularExpression(
            "/require\\(\\[\\s*'IronCart_Scan/",
            $source,
            'RunScanNowButton must not build a require([...]) inline string'
        );

        self::assertMatchesRegularExpression(
            "/'data_attribute'\s*=>/",
            $source,
            'RunScanNowButton must emit a data_attribute array carrying mage-init'
        );
        self::assertMatchesRegularExpression(
            "/'mage-init'\s*=>/",
            $source,
            'RunScanNowButton data_attribute must contain a mage-init key'
        );
        self::assertMatchesRegularExpression(
            "/IronCart_Scan\\/js\\/run-scan-now-init/",
            $source,
            'RunScanNowButton must point mage-init at the run-scan-now-init shim'
        );
        self::assertMatchesRegularExpression(
            "/RUN_URL_PATH\s*=\s*'ironcartscan\\/scans\\/run'/",
            $source,
            'RunScanNowButton must point at the ironcartscan/scans/run route'
        );
        self::assertMatchesRegularExpression(
            "/STATUS_URL_PATH\s*=\s*'ironcartscan\\/scans\\/status'/",
            $source,
            'RunScanNowButton must point at the ironcartscan/scans/status route'
        );
    }

    /**
     * The init shim is the only glue between the declarative
     * `data-mage-init` payload and the unchanged `run-scan-now`
     * module. It must depend on the latter (so the public surface
     * tested elsewhere is exercised) and pass URLs through unchanged.
     */
    public function testInitShimDelegatesIntoRunScanNowModuleWithUrlsFromConfig(): void
    {
        self::assertFileExists(self::JS_INIT_SHIM);
        $source = file_get_contents(self::JS_INIT_SHIM);
        self::assertIsString($source);

        self::assertMatchesRegularExpression(
            "/'IronCart_Scan\\/js\\/run-scan-now'/",
            $source,
            'Init shim must depend on the unchanged IronCart_Scan/js/run-scan-now module'
        );
        self::assertMatchesRegularExpression(
            '/runScanNow\s*\(\s*runUrl\s*,\s*statusUrl\s*\)/',
            $source,
            'Init shim must call runScanNow(runUrl, statusUrl) — public surface from #77'
        );
        self::assertMatchesRegularExpression(
            "/config\\.runUrl/",
            $source,
            'Init shim must pull runUrl from the data-mage-init config payload'
        );
        self::assertMatchesRegularExpression(
            "/config\\.statusUrl/",
            $source,
            'Init shim must pull statusUrl from the data-mage-init config payload'
        );
        self::assertMatchesRegularExpression(
            "/addEventListener\\s*\\(\\s*'click'/",
            $source,
            'Init shim must wire a click listener — replaces the on_click inline handler'
        );
    }

    public function testJsModuleIsPresentAndPostsViaMageStorage(): void
    {
        self::assertFileExists(self::JS_MODULE);
        $source = file_get_contents(self::JS_MODULE);
        self::assertIsString($source);

        // mage/storage automatically appends form_key from window.FORM_KEY
        // for admin POSTs — this is the CSRF token Magento's
        // HttpPostActionInterface expects. Anyone swapping to a raw
        // $.ajax POST would bypass that and we want the test to fail.
        self::assertMatchesRegularExpression(
            "/'mage\\/storage'/",
            $source,
            'JS module must depend on mage/storage (carries admin form_key)'
        );
        self::assertMatchesRegularExpression(
            '/storage\.post\s*\(/',
            $source,
            'JS module must POST via storage.post (preserves form_key handling)'
        );
        self::assertMatchesRegularExpression(
            '/POLL_INTERVAL_MS\s*=\s*2000/',
            $source,
            'JS module must poll at the 2s cadence required by the issue AC'
        );
        self::assertMatchesRegularExpression(
            '/POLL_MAX_DURATION_MS\s*=\s*5\s*\*\s*60\s*\*\s*1000/',
            $source,
            'JS module must stop polling after 5 minutes (issue AC)'
        );
    }

    /**
     * Issue #77 — regression pin for the request-storm fix.
     *
     * Each guard is asserted by a single load-bearing token:
     *
     *   - `MAX_INFLIGHT` constant declared at module scope (global
     *     concurrency ceiling — AC: hard cap on simultaneous GETs).
     *   - `inflightIds` map (per-runId de-dup; ensures the same row
     *     never has two parallel polls in flight).
     *   - `tickInProgress` flag (overlap guard between an outstanding
     *     readVisibleRuns callback and the next setInterval-fired tick).
     *   - `postInFlight` flag (click guard: a second button press while
     *     the enqueue POST is still in flight must drop, not stack a
     *     parallel polling chain).
     *
     * Anyone deleting one of these tokens during a rewrite will trip
     * the regression — the throttling semantics depend on all four.
     */
    public function testJsModuleEnforcesPollingThrottle(): void
    {
        $source = file_get_contents(self::JS_MODULE);
        self::assertIsString($source);

        self::assertMatchesRegularExpression(
            '/var\s+MAX_INFLIGHT\s*=\s*\d+/',
            $source,
            'JS module must declare a global concurrency ceiling (MAX_INFLIGHT) — issue #77 AC'
        );
        self::assertMatchesRegularExpression(
            '/inflightIds\s*\[/',
            $source,
            'JS module must de-dup polls by runId via an inflightIds map — issue #77 AC'
        );
        self::assertMatchesRegularExpression(
            '/tickInProgress/',
            $source,
            'JS module must guard against overlapping ticks with tickInProgress — issue #77 AC'
        );
        self::assertMatchesRegularExpression(
            '/postInFlight/',
            $source,
            'JS module must guard against stacked button-clicks with postInFlight — issue #77 AC'
        );
    }

    /**
     * EQP CSP audit items 29 + 30 — issue #85.
     *
     * The module must ship `etc/csp_whitelist.xml` declaring exactly
     * the outbound hosts it can reach. We assert the file exists, is
     * shaped against the Magento_Csp schema, and includes the
     * `connect-src` entry for `ironcart.dev` — the host every opt-in
     * outbound surface in this module talks to (IC-060 CVE proxy,
     * --upload, v4 cron).
     */
    public function testCspWhitelistDeclaresConnectSrcForIroncartDev(): void
    {
        self::assertFileExists(self::CSP_WHITELIST);
        $xml = simplexml_load_file(self::CSP_WHITELIST);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $found = false;
        foreach ($xml->policies->policy as $policy) {
            if ((string)$policy['id'] !== 'connect-src') {
                continue;
            }
            foreach ($policy->values->value as $value) {
                if ((string)$value === 'ironcart.dev'
                    && (string)$value['type'] === 'host'
                ) {
                    $found = true;
                    break 2;
                }
            }
        }

        self::assertTrue(
            $found,
            'etc/csp_whitelist.xml must declare ironcart.dev as a connect-src host'
        );
    }
}
