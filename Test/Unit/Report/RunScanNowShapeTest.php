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

    public function testButtonClassRendersDispatchIntoRequireJsModule(): void
    {
        $source = file_get_contents(self::BUTTON_CLASS);
        self::assertIsString($source);

        self::assertMatchesRegularExpression(
            "/IronCart_Scan\\/js\\/run-scan-now/",
            $source,
            'RunScanNowButton must dispatch into IronCart_Scan/js/run-scan-now'
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
}
