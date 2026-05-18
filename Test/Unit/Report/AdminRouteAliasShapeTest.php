<?php

/**
 * IronCart_Scan — admin default-route alias shape pin (issue #120).
 *
 * Magento's admin router expands a bare frontName URL like
 * `/admin/ironcartscan/` to `<frontName>/index/index` before dispatch.
 * The v1 landing controller lives at `Adminhtml/Scans/Index`, so
 * without a class at `Adminhtml/Index/Index` the bare URL — and any
 * stale bookmark pointing at `.../index/index/` — 404s.
 *
 * This test pins the alias controller's presence and load-bearing
 * shape so a future refactor doesn't silently re-open the 404. It
 * follows the same parse-the-source convention as
 * {@see RunScanNowShapeTest} because the Magento-free unit cell
 * cannot instantiate `Magento\Backend\App\Action` subclasses
 * (magento/framework is stripped from the autoloader — see
 * .github/workflows/ci.yml "Generate vendor autoloader" step).
 *
 * What this test does NOT do:
 *   - Boot Magento or dispatch the controller.
 *   - Verify the HTTP redirect lands at the canonical URL in a
 *     browser — that is the integration / smoke job's responsibility.
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
class AdminRouteAliasShapeTest extends TestCase
{
    private const MODULE_ROOT = __DIR__ . '/../../..';
    private const ALIAS_CONTROLLER = self::MODULE_ROOT . '/Controller/Adminhtml/Index/Index.php';
    private const ROUTES_XML = self::MODULE_ROOT . '/etc/adminhtml/routes.xml';
    private const MENU_XML = self::MODULE_ROOT . '/etc/adminhtml/menu.xml';
    private const CANONICAL_PATH = 'ironcartscan/scans/index';

    public function testAliasControllerExistsForBareFrontName(): void
    {
        // The whole point of this fixture: Magento's
        // `<frontName>/index/index` fallback must resolve to a real
        // class. The absence of this file IS the bug from #120.
        self::assertFileExists(
            self::ALIAS_CONTROLLER,
            'Controller/Adminhtml/Index/Index.php must exist so '
            . '/admin/ironcartscan/ and /admin/ironcartscan/index/index/ '
            . 'do not 404 (issue #120).'
        );
    }

    public function testAliasControllerDeclaresViewAcl(): void
    {
        $source = (string)file_get_contents(self::ALIAS_CONTROLLER);

        // ADMIN_RESOURCE = 'IronCart_Scan::view' — the redirect lands
        // on the listing, which gates on ::view, so the alias must
        // gate identically. Anyone tightening this to ::run would
        // wall-off the bare URL for view-only admins; anyone removing
        // it entirely would let unprivileged admins probe the route.
        self::assertMatchesRegularExpression(
            "/ADMIN_RESOURCE\s*=\s*'IronCart_Scan::view'/",
            $source,
            'alias controller must gate on IronCart_Scan::view'
        );
    }

    public function testAliasControllerImplementsHttpGetMarker(): void
    {
        $source = (string)file_get_contents(self::ALIAS_CONTROLLER);

        // The HttpGetActionInterface marker is what Magento's CSRF +
        // method-enforcement plumbing keys off. A redirect controller
        // is GET-only by definition; flipping this to Post would
        // expose the redirect to form-key requirements it doesn't need.
        self::assertStringContainsString(
            'HttpGetActionInterface',
            $source,
            'alias controller must implement HttpGetActionInterface'
        );
    }

    public function testAliasControllerRedirectsToCanonicalScansLanding(): void
    {
        $source = (string)file_get_contents(self::ALIAS_CONTROLLER);

        // The redirect target is load-bearing: pointing it at any
        // other path either re-creates the 404 or lands on a page
        // the admin user lacks the ACL to view.
        self::assertMatchesRegularExpression(
            "/CANONICAL_PATH\s*=\s*'" . preg_quote(self::CANONICAL_PATH, '/') . "'/",
            $source,
            'alias controller must redirect to ' . self::CANONICAL_PATH
        );
        self::assertStringContainsString(
            'TYPE_REDIRECT',
            $source,
            'alias controller must create a redirect result, not a page'
        );
    }

    public function testAdminRoutesXmlDeclaresIroncartscanFrontName(): void
    {
        // Belt-and-braces: the alias controller is moot if the
        // frontName isn't registered under the admin router. Pin the
        // route id + module name so a rename can't silently break the
        // alias and the canonical landing at the same time.
        self::assertFileExists(self::ROUTES_XML);
        $xml = simplexml_load_file(self::ROUTES_XML);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $route = $xml->router->route ?? null;
        self::assertNotNull($route, 'admin routes.xml must declare a <route>');
        self::assertSame(
            'ironcartscan',
            (string)$route['id'],
            'admin route id must remain `ironcartscan`'
        );
        self::assertSame(
            'ironcartscan',
            (string)$route['frontName'],
            'admin route frontName must remain `ironcartscan`'
        );

        $module = $route->module ?? null;
        self::assertNotNull($module, '<route> must declare its <module>');
        self::assertSame(
            'IronCart_Scan',
            (string)$module['name'],
            '<module name="..."> must match the registered module name'
        );
    }

    public function testMenuItemPointsAtCanonicalLandingNotBareFrontName(): void
    {
        // Defends the canonical menu wiring: the System -> Tools menu
        // entry must point at `ironcartscan/scans/index`, not at the
        // bare `ironcartscan` (which would force every menu click
        // through the alias redirect even though the canonical URL
        // is reachable directly).
        $xml = simplexml_load_file(self::MENU_XML);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $add = $xml->menu->add ?? null;
        self::assertNotNull($add, 'menu.xml must declare a <menu><add>');
        self::assertSame(
            self::CANONICAL_PATH,
            (string)$add['action'],
            'menu action must remain ' . self::CANONICAL_PATH
        );
    }
}
