<?php

/**
 * IronCart_Scan — dismissible "enable CVE lookup" recommendation banner.
 *
 * Renders above the run-listing and scan-detail admin pages whenever the
 * opt-in IC-060 CVE cross-reference flag (`ironcart_scan/cve/enabled`) is
 * still off. The flag is off by default — see {@see ComposerCveCheck}
 * for the read-only / outbound-network rationale — so most merchants
 * never discover the feature exists. A quiet banner on the pages where
 * they already look at scan output is the cheapest surface to advertise
 * value without nagging anyone who has actively chosen no-outbound.
 *
 * ## Dismissal model
 *
 * Dismissal is **per-browser via localStorage**, not per-admin-user.
 * Issue #183 explicitly scopes the v1 to "no schema migration, no admin
 * preference table" — keeping the banner state client-side keeps the
 * change cosmetic. The localStorage key is
 * `ironcart_scan_cve_banner_dismissed`; clearing it re-shows the banner.
 * The key + dismissal handler live in the RequireJS module
 * `IronCart_Scan/js/cve-lookup-banner`, wired via the standard
 * `data-mage-init` declarative pattern (no inline JS — see #85 / EQP
 * audit items 29 + 30 and `etc/csp_whitelist.xml`).
 *
 * ## Render gate
 *
 * When `ironcart_scan/cve/enabled` is already truthy, {@see _toHtml()}
 * returns `''` and Magento drops the block from the output. We do NOT
 * just hide it in CSS — emitting markup advocating an already-enabled
 * feature is a worse UX than nothing.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Block\Adminhtml;

use IronCart\Scan\Check\Cve\ComposerCveCheck;
use Magento\Backend\Block\Template;

class CveLookupBanner extends Template
{
    /**
     * localStorage key the dismissal handler writes. Documented here
     * (not just in the JS shim) so PR reviewers and support can re-show
     * the banner during testing without spelunking the bundle.
     *
     * Re-enable visibility: `localStorage.removeItem('ironcart_scan_cve_banner_dismissed')`.
     */
    public const DISMISS_STORAGE_KEY = 'ironcart_scan_cve_banner_dismissed';

    /**
     * RequireJS module id wired via `data-mage-init`. Maps to
     * `view/adminhtml/web/js/cve-lookup-banner.js` through Magento's
     * default `<Vendor>_<Module>/js/<file>` path resolver.
     */
    private const INIT_MODULE_ID = 'IronCart_Scan/js/cve-lookup-banner';

    /**
     * Admin sysconfig section for the CVE flag (matches `etc/adminhtml/system.xml`).
     * The banner links here so a click goes straight to the setting,
     * not to a generic "Configuration" landing.
     */
    private const SYSCONFIG_SECTION = 'ironcart_scan';

    /**
     * @var string
     */
    protected $_template = 'IronCart_Scan::cve_lookup_banner.phtml';

    /**
     * Skip rendering entirely when the merchant has already opted in.
     *
     * Returns an empty string when the CVE-lookup flag is on so the
     * layout-rendered block becomes a no-op rather than emitting an
     * "enable a thing you already enabled" message.
     */
    protected function _toHtml(): string
    {
        if ($this->isCveLookupEnabled()) {
            return '';
        }
        return parent::_toHtml();
    }

    /**
     * Whether the IC-060 CVE cross-reference flag is currently on.
     *
     * Public so the phtml template can call it defensively; the
     * `_toHtml()` early-return is the load-bearing gate.
     */
    public function isCveLookupEnabled(): bool
    {
        return $this->_scopeConfig->isSetFlag(ComposerCveCheck::CONFIG_ENABLED);
    }

    /**
     * Admin URL to the CVE-lookup configuration row.
     *
     * Links straight into the existing System Configuration page at
     * Stores → Configuration → Ironcart → Scan → CVE cross-reference.
     * Anchoring the URL to the section keeps the click landing on the
     * correct group without requiring a separate route.
     */
    public function getConfigUrl(): string
    {
        return $this->getUrl(
            'adminhtml/system_config/edit',
            ['section' => self::SYSCONFIG_SECTION]
        );
    }

    /**
     * JSON payload consumed by `mage/apply/main.js` to bootstrap the
     * dismissal handler against the banner's root element. The
     * RequireJS module id is the key; its value is the config object
     * passed to the module's exported function.
     *
     * Encoded server-side and embedded into a `data-mage-init`
     * attribute; the template's Magento `escapeHtmlAttr` round-trips
     * the value cleanly because the JSON contains only ASCII keys and
     * a single string value.
     */
    public function getMageInitJson(): string
    {
        $payload = [
            self::INIT_MODULE_ID => [
                'storageKey' => self::DISMISS_STORAGE_KEY,
            ],
        ];
        return (string)json_encode($payload);
    }
}
