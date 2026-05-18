<?php

/**
 * IronCart_Scan — "Run scan now" header button on the run-listing.
 *
 * Implements Magento's `ButtonProviderInterface` so the button is
 * declared declaratively in `ironcartscan_run_listing.xml` via the
 * UI Component `buttons` argument array (no PHTML / layout XML).
 *
 * ## Declarative `data-mage-init` wiring (issue #85 / EQP CSP)
 *
 * Earlier revisions of this class emitted a literal
 * `require([...], function (run) { run(<json>, <json>); });` string as
 * the button's `on_click` attribute. Magento's `Container` template
 * renders `on_click` straight into an inline `onclick="..."` handler,
 * which is a strict-CSP violation and is flagged by the Adobe
 * Marketplace EQP CSP review (audit items 29 + 30 in
 * `docs/marketplace-eqp-audit.md`).
 *
 * The replacement is the standard Magento declarative pattern: emit a
 * `data-mage-init` attribute carrying a JSON object whose keys are
 * RequireJS module ids and whose values are config payloads. The
 * client-side `mage/apply/main.js` bootstrap (already on every admin
 * page) walks the DOM on `DOMContentLoaded`, resolves each module
 * named in `data-mage-init`, and invokes the module with the config
 * object and the DOM element. The shim at
 * `view/adminhtml/web/js/run-scan-now-init.js` is a five-line
 * adapter that pulls `runUrl` / `statusUrl` out of the config object
 * and binds a regular `click` listener on the button that calls into
 * the existing `view/adminhtml/web/js/run-scan-now.js` module — its
 * exported `runScanNow(runUrl, statusUrl)` signature, and therefore
 * the #77 throttling regression suite, remain unchanged.
 *
 * Magento's UI Component button renderer
 * (`vendor/magento/module-ui/view/base/web/templates/grid/toolbar.html`
 * et al.) recognises a `data_attribute` key on the button-provider
 * array and renders each entry as a `data-<name>="<value>"` attribute
 * on the rendered button. The value is HTML-escaped at render time;
 * we encode the inner JSON ourselves so the entity-decoded string
 * `mage/apply` sees back is valid JSON. No inline JS is rendered.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Ui\Component\Control;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class RunScanNowButton implements ButtonProviderInterface
{
    /**
     * Admin route for the POST controller.
     */
    private const RUN_URL_PATH = 'ironcartscan/scans/run';

    /**
     * Admin route for the GET polling controller.
     */
    private const STATUS_URL_PATH = 'ironcartscan/scans/status';

    /**
     * RequireJS module id the `mage/apply` bootstrap will resolve from
     * the `data-mage-init` payload. Mapped to the shim file via
     * `view/adminhtml/requirejs-config.js` — not strictly necessary
     * because Magento's RequireJS path resolver already understands
     * `<Vendor>_<Module>/js/<file>`, but the explicit constant keeps
     * the module-id surface in one place if the file ever moves.
     */
    private const INIT_MODULE_ID = 'IronCart_Scan/js/run-scan-now-init';

    public function __construct(
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @return array{label:string,class:string,sort_order:int,data_attribute:array<string,string>}
     */
    public function getButtonData(): array
    {
        // Pre-compute both URLs server-side so the JS shim receives
        // them as plain strings — no URL building in the browser.
        $runUrl = $this->urlBuilder->getUrl(self::RUN_URL_PATH);
        $statusUrl = $this->urlBuilder->getUrl(self::STATUS_URL_PATH);

        // `data-mage-init` is a JSON object keyed by RequireJS module id.
        // The HTML-attribute round-trip (PHP encode → toolbar template
        // `escapeHtml()` → browser parse → `mage/apply` JSON.parse) is
        // safe with default `json_encode()` flags: the template escapes
        // the attribute value, and `mage/apply` decodes the entity-
        // encoded form back to the original JSON. The JSON_HEX_* flags
        // from the prior implementation were defence-in-depth against
        // the inline-JS context — that context is gone in this revision,
        // so we drop them and rely on the template's escapeHtml().
        $payload = [
            self::INIT_MODULE_ID => [
                'runUrl'    => $runUrl,
                'statusUrl' => $statusUrl,
            ],
        ];

        return [
            'label'          => (string)__('Run scan now'),
            'class'          => 'primary',
            'sort_order'     => 10,
            'data_attribute' => [
                'mage-init' => (string)json_encode($payload),
            ],
        ];
    }
}
