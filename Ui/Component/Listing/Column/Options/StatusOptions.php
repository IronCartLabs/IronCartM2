<?php

/**
 * IronCart_Scan — option source for the `status` filter on the scan-run grid.
 *
 * Lists every status constant declared on {@see \IronCart\Scan\Model\ScanRun}
 * so admin users can filter the grid with a dropdown rather than a free-form
 * text input. Keeping the option source out of XML lets future status
 * additions (e.g. `cancelled` from a stop-run feature) extend the enum
 * without a UI Component XML edit.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Ui\Component\Listing\Column\Options;

use IronCart\Scan\Model\ScanRun;
use Magento\Framework\Data\OptionSourceInterface;

class StatusOptions implements OptionSourceInterface
{
    /**
     * {@inheritDoc}
     *
     * @return list<array{value:string,label:string}>
     */
    public function toOptionArray(): array
    {
        $statuses = [
            ScanRun::STATUS_QUEUED,
            ScanRun::STATUS_RUNNING,
            ScanRun::STATUS_SUCCEEDED,
            ScanRun::STATUS_FAILED,
        ];
        $out = [];
        foreach ($statuses as $status) {
            $out[] = [
                'value' => $status,
                'label' => ucfirst($status),
            ];
        }
        return $out;
    }
}
