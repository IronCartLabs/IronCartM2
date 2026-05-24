<?php

/**
 * IronCart_Scan — CVE-lookup banner shape smoke test.
 *
 * Pins the wiring for the #183 banner without booting Magento:
 *   - Both admin scan layouts declare the banner block above their
 *     UI Component.
 *   - The block class file exists, declares the expected namespace,
 *     extends `Magento\Backend\Block\Template`, and binds the
 *     phtml template.
 *   - The phtml template renders `data-mage-init` (no inline JS),
 *     a close button with the dismissal role, and a link to the
 *     sysconfig section.
 *   - The RequireJS dismissal module exists.
 *   - The localStorage key used by the JS shim matches the constant
 *     advertised by the Block class.
 *
 * Lives under Test/Unit/Report for the same reason AdminUiShapeTest
 * does — it's the only test subtree the unit-CI cell loads
 * (see .github/workflows/ci.yml). All assertions are pure file /
 * XML reads; no Magento types are autoloaded.
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
class CveBannerShapeTest extends TestCase
{
    private const MODULE_ROOT = __DIR__ . '/../../..';
    private const LAYOUT_INDEX = self::MODULE_ROOT . '/view/adminhtml/layout/ironcartscan_scans_index.xml';
    private const LAYOUT_VIEW = self::MODULE_ROOT . '/view/adminhtml/layout/ironcartscan_scans_view.xml';
    private const BLOCK_PHP = self::MODULE_ROOT . '/Block/Adminhtml/CveLookupBanner.php';
    private const TEMPLATE_PHTML = self::MODULE_ROOT . '/view/adminhtml/templates/cve_lookup_banner.phtml';
    private const JS_MODULE = self::MODULE_ROOT . '/view/adminhtml/web/js/cve-lookup-banner.js';

    private const BLOCK_FQCN = 'IronCart\\Scan\\Block\\Adminhtml\\CveLookupBanner';
    private const BLOCK_NAME = 'ironcart_scan_cve_banner';
    private const BLOCK_TEMPLATE = 'IronCart_Scan::cve_lookup_banner.phtml';
    private const EXPECTED_STORAGE_KEY = 'ironcart_scan_cve_banner_dismissed';

    /**
     * @return array<string,array{string}>
     */
    public static function layoutsProvider(): array
    {
        return [
            'scans index layout' => [self::LAYOUT_INDEX],
            'scans view layout' => [self::LAYOUT_VIEW],
        ];
    }

    /**
     * @dataProvider layoutsProvider
     */
    public function testLayoutDeclaresBannerBlock(string $layoutPath): void
    {
        self::assertFileExists($layoutPath);

        $xml = simplexml_load_file($layoutPath);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $bannerBlock = null;
        foreach ($xml->body->referenceContainer->block ?? [] as $candidate) {
            if ((string)$candidate['name'] === self::BLOCK_NAME) {
                $bannerBlock = $candidate;
                break;
            }
        }
        self::assertNotNull(
            $bannerBlock,
            "layout {$layoutPath} must declare a <block name=\"" . self::BLOCK_NAME . "\"/>"
        );

        self::assertSame(
            self::BLOCK_FQCN,
            (string)$bannerBlock['class'],
            "banner block in {$layoutPath} must bind the CveLookupBanner class"
        );
        self::assertSame(
            self::BLOCK_TEMPLATE,
            (string)$bannerBlock['template'],
            "banner block in {$layoutPath} must bind the cve_lookup_banner template"
        );
    }

    /**
     * @dataProvider layoutsProvider
     */
    public function testLayoutPositionsBannerBeforeUiComponent(string $layoutPath): void
    {
        // The banner only earns the prominence the issue asks for if it
        // renders above the scan listing, not below it. Magento orders
        // siblings inside a container by `before`/`after` attributes;
        // `before="-"` is the documented "first child" idiom.
        $xml = simplexml_load_file($layoutPath);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $bannerBlock = null;
        foreach ($xml->body->referenceContainer->block ?? [] as $candidate) {
            if ((string)$candidate['name'] === self::BLOCK_NAME) {
                $bannerBlock = $candidate;
                break;
            }
        }
        self::assertNotNull($bannerBlock, 'banner block must exist before position check');

        self::assertSame(
            '-',
            (string)$bannerBlock['before'],
            "banner block in {$layoutPath} must declare before=\"-\" so it renders above the listing"
        );
    }

    public function testBlockClassFileDeclaresExpectedStructure(): void
    {
        self::assertFileExists(self::BLOCK_PHP);
        $source = (string)file_get_contents(self::BLOCK_PHP);

        self::assertStringContainsString(
            'namespace IronCart\\Scan\\Block\\Adminhtml;',
            $source,
            'block must live in IronCart\\Scan\\Block\\Adminhtml'
        );
        self::assertMatchesRegularExpression(
            '/class\s+CveLookupBanner\s+extends\s+Template\b/',
            $source,
            'block must extend Magento\\Backend\\Block\\Template (aliased as Template)'
        );
        self::assertStringContainsString(
            'use Magento\\Backend\\Block\\Template;',
            $source,
            'block must import the Backend Template base class (admin theme, not frontend)'
        );
        self::assertStringContainsString(
            "'IronCart_Scan::cve_lookup_banner.phtml'",
            $source,
            'block must bind the cve_lookup_banner phtml template'
        );
        self::assertStringContainsString(
            'ComposerCveCheck::CONFIG_ENABLED',
            $source,
            'block must read the IC-060 opt-in flag via the existing constant'
        );
        self::assertStringContainsString(
            "DISMISS_STORAGE_KEY = '" . self::EXPECTED_STORAGE_KEY . "'",
            $source,
            'block must expose the localStorage key advertised in the PR description'
        );
        self::assertMatchesRegularExpression(
            '/protected\s+function\s+_toHtml\s*\(\s*\)\s*:\s*string/',
            $source,
            'block must override _toHtml() to gate output on the flag'
        );
    }

    public function testTemplateUsesMagentoMessageNoticeStyling(): void
    {
        self::assertFileExists(self::TEMPLATE_PHTML);
        $template = (string)file_get_contents(self::TEMPLATE_PHTML);

        self::assertStringContainsString(
            'message message-notice',
            $template,
            'template must use Magento standard admin message-notice class'
        );
        self::assertStringContainsString(
            'data-mage-init=',
            $template,
            'template must wire the dismissal handler via data-mage-init (no inline JS)'
        );
        self::assertStringNotContainsString(
            'onclick=',
            $template,
            'template must not use inline onclick handlers (strict-CSP / EQP)'
        );
        self::assertStringNotContainsString(
            '<script',
            $template,
            'template must not embed inline <script> tags (strict-CSP / EQP)'
        );
        self::assertStringContainsString(
            'data-role="ironcart-scan-cve-banner-dismiss"',
            $template,
            'template must mark the close control with the dismissal role'
        );
        self::assertStringContainsString(
            '$block->getConfigUrl()',
            $template,
            'template must link to the sysconfig URL the block emits'
        );
    }

    public function testTemplateEscapesEveryDynamicValue(): void
    {
        // EQP forbids unescaped output. Every dynamic insertion in this
        // template should go through $escaper — keep the regex tight to
        // catch a future "drop the escaper" regression.
        $template = (string)file_get_contents(self::TEMPLATE_PHTML);

        // Any `<?= ` opener must be followed within a few characters by
        // an $escaper call. We grep for the inverse: any raw `<?= ` that
        // isn't followed by `$escaper->`.
        preg_match_all('/<\?=\s*(.+?)\s*\?>/s', $template, $matches);
        foreach ($matches[1] as $expression) {
            self::assertStringContainsString(
                '$escaper->',
                $expression,
                "template expression must escape its output: `{$expression}`"
            );
        }
    }

    public function testJsModuleIsDefineWrappedAndUsesAdvertisedStorageKey(): void
    {
        self::assertFileExists(self::JS_MODULE);
        $js = (string)file_get_contents(self::JS_MODULE);

        self::assertMatchesRegularExpression(
            '/define\s*\(\s*\[\s*\]\s*,/',
            $js,
            'JS module must be a no-dependency define() (loaded via data-mage-init)'
        );
        self::assertStringContainsString(
            self::EXPECTED_STORAGE_KEY,
            $js,
            'JS module must default to the storage key advertised by the block'
        );
        self::assertStringContainsString(
            'localStorage',
            $js,
            'JS module must use localStorage for per-browser dismissal'
        );
        self::assertStringContainsString(
            "data-role=\"ironcart-scan-cve-banner-dismiss\"",
            $js,
            'JS module must look up the close control by the documented data-role'
        );
        self::assertDoesNotMatchRegularExpression(
            '/\beval\s*\(/',
            $js,
            'JS module must not use eval()'
        );
    }

    public function testJsModuleStorageKeyMatchesBlockConstant(): void
    {
        // Cross-pin: the Block class constant and the JS default must
        // agree. Anyone changing one without the other will see this
        // test fail and need to think about backwards compatibility
        // (existing dismissals are keyed by the old string).
        $blockSource = (string)file_get_contents(self::BLOCK_PHP);
        $js = (string)file_get_contents(self::JS_MODULE);

        $matched = preg_match(
            '/DISMISS_STORAGE_KEY\s*=\s*\'([^\']+)\'/',
            $blockSource,
            $blockMatch
        );
        self::assertSame(1, $matched, 'block must declare DISMISS_STORAGE_KEY string constant');

        $matched = preg_match(
            '/DEFAULT_STORAGE_KEY\s*=\s*\'([^\']+)\'/',
            $js,
            $jsMatch
        );
        self::assertSame(1, $matched, 'JS module must declare DEFAULT_STORAGE_KEY string');

        self::assertSame(
            $blockMatch[1],
            $jsMatch[1],
            'Block::DISMISS_STORAGE_KEY and JS DEFAULT_STORAGE_KEY must agree'
        );
    }
}
