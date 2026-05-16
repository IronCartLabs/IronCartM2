<?php

/**
 * IronCart_Scan — option source for the `severity` filter on the findings grid.
 *
 * Sourced from {@see \IronCart\Scan\Report\Severity::ALL} so the vocabulary
 * stays canonical — adding a future severity (e.g. `notice`) requires only
 * a Severity constant + ::ALL entry; the grid filter follows.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Ui\Component\Listing\Column\Options;

use IronCart\Scan\Report\Severity;
use Magento\Framework\Data\OptionSourceInterface;

class SeverityOptions implements OptionSourceInterface
{
    /**
     * {@inheritDoc}
     *
     * @return list<array{value:string,label:string}>
     */
    public function toOptionArray(): array
    {
        $out = [];
        foreach (Severity::ALL as $severity) {
            $out[] = [
                'value' => $severity,
                'label' => ucfirst($severity),
            ];
        }
        return $out;
    }
}
