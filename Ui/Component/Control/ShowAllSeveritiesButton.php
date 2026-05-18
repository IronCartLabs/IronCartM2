<?php

/**
 * IronCart_Scan — header button on the findings detail UI Component.
 *
 * Implements Magento's `ButtonProviderInterface` so the button can be
 * declared declaratively in the finding-listing XML without a
 * `<container>` block in the layout. The button's label and link
 * direction toggle based on the current `showAll` flag:
 *
 *   - Default (filter active):    "Show all severities" → ?showAll=1
 *   - After toggle (filter off):  "Show critical only" → strip showAll
 *
 * The button is a plain anchor (not a JS-driven form post) so the
 * toggle does not require a Knockout template. The route param flips
 * per-page-render only — no persistence — which matches the AC
 * ("removes the severity filter for the current session"). The
 * detail-view controller mirrors the URL param into the admin
 * session bucket so the grid's data-provider XHR can read it (see
 * issue #97).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Ui\Component\Control;

use IronCart\Scan\Ui\DataProvider\ScanFindingDataProvider;
use IronCart\Scan\Ui\DataProvider\ShowAllFlag;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class ShowAllSeveritiesButton implements ButtonProviderInterface
{
    /**
     * Admin route for the detail-view controller. Matches the URL
     * compiled by {@see \IronCart\Scan\Ui\Component\Listing\Column\ViewAction}
     * so a round-trip preserves the visited run.
     */
    private const URL_PATH = 'ironcartscan/scans/view';

    public function __construct(
        private readonly UrlInterface $urlBuilder,
        private readonly RequestInterface $request
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @return array{label:string,on_click:string,class:string,sort_order:int}
     */
    public function getButtonData(): array
    {
        $runId = (int)$this->request->getParam(ScanFindingDataProvider::RUN_PARAM, 0);
        $isShowingAll = $this->isShowAllActive();

        $params = ['id' => $runId];
        if (!$isShowingAll) {
            $params[ScanFindingDataProvider::SHOW_ALL_PARAM] = 1;
        }

        return [
            'label'      => $isShowingAll
                ? (string)__('Show critical only')
                : (string)__('Show all severities'),
            // `on_click` is the documented route — `setLocation(...)` is
            // what every Magento admin "back/secondary" button uses so
            // we keep parity rather than reaching for new JS.
            'on_click'   => sprintf(
                "setLocation('%s')",
                $this->urlBuilder->getUrl(self::URL_PATH, $params)
            ),
            'class'      => 'secondary',
            'sort_order' => 10,
        ];
    }

    /**
     * Whether the current page request URL is asking for the
     * lifted-filter view. The button is rendered as part of the page
     * response — same request scope as the detail-view controller —
     * so the URL `?showAll` param is the authoritative source here.
     * Delegates to {@see ShowAllFlag::isTruthy} so the truthy-rule
     * stays in lockstep with the data provider's session reader.
     */
    private function isShowAllActive(): bool
    {
        return ShowAllFlag::isTruthy(
            $this->request->getParam(ScanFindingDataProvider::SHOW_ALL_PARAM)
        );
    }
}
