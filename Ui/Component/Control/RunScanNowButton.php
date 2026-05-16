<?php

/**
 * IronCart_Scan — "Run scan now" header button on the run-listing.
 *
 * Implements Magento's `ButtonProviderInterface` so the button is
 * declared declaratively in `ironcartscan_run_listing.xml` (matches
 * the existing {@see ShowAllSeveritiesButton} pattern on the findings
 * listing). The `on_click` hands off to the RequireJS module
 * `IronCart_Scan/js/run-scan-now`, which:
 *
 *   1. POSTs to `ironcartscan/scans/run` with the admin form key.
 *   2. Reads `{ runId, status }` back and starts the polling loop.
 *   3. Reloads the grid data source via uiRegistry so the new row
 *      appears immediately and subsequent polls update its status.
 *
 * Why a require() call inline rather than a phtml-mounted script: the
 * listing layout (`ironcartscan_scans_index.xml`) only attaches the UI
 * Component — there is no block where a phtml could mount JS, and the
 * UI Component buttons block does not accept a `<script>` child. The
 * cleanest available hook is to compile the require() into `on_click`
 * and let the requirejs-config.js mapping resolve the module path.
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

    public function __construct(
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @return array{label:string,on_click:string,class:string,sort_order:int}
     */
    public function getButtonData(): array
    {
        // Pre-compute both URLs server-side so the JS module receives
        // them as plain strings — no URL building in the browser.
        $runUrl = $this->urlBuilder->getUrl(self::RUN_URL_PATH);
        $statusUrl = $this->urlBuilder->getUrl(self::STATUS_URL_PATH);

        // Magento's button renderer wraps `on_click` in a click handler.
        // We dispatch into a dedicated RequireJS module so the actual
        // POST + polling logic lives in
        // view/adminhtml/web/js/run-scan-now.js — mapped to
        // `IronCart_Scan/js/run-scan-now` via requirejs-config.js.
        $onClick = sprintf(
            "require(['IronCart_Scan/js/run-scan-now'], function (run) { run(%s, %s); });",
            json_encode($runUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            json_encode($statusUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        );

        return [
            'label'      => (string)__('Run scan now'),
            'on_click'   => $onClick,
            'class'      => 'primary',
            'sort_order' => 10,
        ];
    }
}
