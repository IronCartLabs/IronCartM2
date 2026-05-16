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
 * per-request only — no persistence — which matches the AC ("removes
 * the severity filter for the current session").
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Ui\Component\Control;

use IronCart\Scan\Ui\DataProvider\ScanFindingDataProvider;
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
     * Whether the current request has the showAll flag set. Kept in
     * sync with {@see ScanFindingDataProvider::isShowAllRequested}.
     */
    private function isShowAllActive(): bool
    {
        $param = $this->request->getParam(ScanFindingDataProvider::SHOW_ALL_PARAM);
        if ($param === null || $param === '' || $param === '0' || $param === false) {
            return false;
        }
        return true;
    }
}
