/**
 * IronCart_Scan — `data-mage-init` adapter for the admin "Run scan
 * now" button.
 *
 * Why this file exists (issue #85 / EQP CSP refactor):
 *
 *   Before — `RunScanNowButton::getButtonData()` emitted an
 *   `on_click` attribute carrying a literal
 *   `require([...], function (run) { run(<json>, <json>); });`
 *   expression. Magento's toolbar template renders `on_click` straight
 *   into an inline `onclick="..."` HTML attribute, which trips strict
 *   CSP and the Adobe Marketplace EQP CSP review (audit items 29 + 30
 *   in `docs/marketplace-eqp-audit.md`).
 *
 *   After — `RunScanNowButton` now ships a `data-mage-init` payload
 *   pointing at this module. Magento's `mage/apply/main.js` bootstrap
 *   resolves the module on DOM ready and invokes the function below
 *   with the config object and the button element. We wire a regular
 *   click listener on the element and dispatch into the unchanged
 *   `IronCart_Scan/js/run-scan-now` module — its
 *   `runScanNow(runUrl, statusUrl)` public surface (and therefore the
 *   #77 polling-throttle regression tests) stays exactly the same.
 *
 * Config shape:
 *
 *   {
 *     "runUrl":    "/admin/.../ironcartscan/scans/run/key/<form_key>/",
 *     "statusUrl": "/admin/.../ironcartscan/scans/status/key/<form_key>/"
 *   }
 *
 * The URLs are pre-built server-side by the button provider — we never
 * construct URLs in the browser. The function is registered with
 * `define([], ...)` because everything we need is async-loaded by the
 * `run-scan-now` module itself; this shim depends on nothing.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */
define([
    'IronCart_Scan/js/run-scan-now'
], function (runScanNow) {
    'use strict';

    /**
     * `mage/apply` invokes this with `(config, element)`. `element` is
     * the DOM node carrying the `data-mage-init` attribute — for the
     * "Run scan now" button it is the `<button>` itself.
     *
     * We attach a regular `click` listener that calls the unchanged
     * `runScanNow` entry point. The listener is `addEventListener` so
     * this never replaces or overwrites any other listener Magento's
     * toolbar might attach (e.g. ripple/focus styles).
     */
    return function (config, element) {
        if (!element || typeof element.addEventListener !== 'function') {
            return;
        }

        var runUrl = config && config.runUrl;
        var statusUrl = config && config.statusUrl;

        if (typeof runUrl !== 'string' || typeof statusUrl !== 'string') {
            return;
        }

        element.addEventListener('click', function (event) {
            // The toolbar button renders as a real <button> inside a
            // <form>-less context, but admin pages do occasionally wrap
            // ad-hoc forms around grid actions. Stop the default in
            // case anyone ever embeds this listing inside a form so a
            // double-submit can't fire.
            event.preventDefault();
            runScanNow(runUrl, statusUrl);
        });
    };
});
