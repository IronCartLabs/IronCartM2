<?php

/**
 * IronCart_Scan — admin grid renderer for the deprecation badge.
 *
 * Decorates the `check_id` cell with a `[deprecated]` chip + tooltip
 * pointing at the migration doc, when the row's check id is registered
 * in {@see DeprecationRegistry}. Otherwise the cell renders the raw
 * check id unchanged.
 *
 * Wired into `view/adminhtml/ui_component/ironcartscan_finding_listing.xml`
 * as the column class for `check_id`. The render uses Magento's built-in
 * `grid-severity-notice` chip style (cyan) so the badge is visually
 * distinct from the {@see SeverityBadge} colours (red / yellow / cyan)
 * without inventing new CSS.
 *
 * The tooltip text is sourced from {@see DeprecationRegistry::notice()}
 * so the admin tooltip stays in lock-step with the CLI stderr notice
 * copy — operators see the same migration message in both surfaces.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Ui\Component\Listing\Column;

use IronCart\Scan\Check\DeprecationRegistry;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class DeprecationBadge extends Column
{
    /**
     * @param array<string,mixed>  $components
     * @param array<string,mixed>  $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly DeprecationRegistry $deprecations,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array{data:array{items:list<array<string,mixed>>}} $dataSource
     *
     * @return array<string,mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $field = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            $checkId = (string)($item[$field] ?? '');
            $escapedId = htmlspecialchars($checkId, ENT_QUOTES, 'UTF-8');

            if (!$this->deprecations->isDeprecated($checkId)) {
                // Untouched — render the raw check id.
                $item[$field] = $escapedId;
                continue;
            }

            $meta = $this->deprecations->metadataFor($checkId);
            if ($meta === null) {
                // isDeprecated returned true but metadataFor returned null
                // — invariant violation in the registry. Render plain.
                $item[$field] = $escapedId;
                continue;
            }

            $tooltip = sprintf(
                'Deprecated in v%s, moves to %s in v%s.',
                $meta['deprecated_in'],
                $meta['replacement'],
                $meta['removal_in']
            );
            $tooltipEsc = htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8');
            $migrationUrlEsc = htmlspecialchars($meta['migration_url'], ENT_QUOTES, 'UTF-8');

            $item[$field] = sprintf(
                '%s <a href="%s" target="_blank" rel="noopener noreferrer" title="%s">'
                . '<span class="grid-severity-notice ironcart-deprecated-badge">'
                . '<span>deprecated</span></span></a>',
                $escapedId,
                $migrationUrlEsc,
                $tooltipEsc
            );
        }
        unset($item);

        return $dataSource;
    }
}
