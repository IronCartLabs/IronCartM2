<?php

/**
 * IronCart_Scan — data patch backfilling `ironcart_scan_run.finding_count`
 * from the existing `summary_json` blob on historical rows.
 *
 * Schema change for issue #118 added the `finding_count` scalar column
 * so the admin run-listing grid can pushdown a numeric-range filter.
 * For installs that already have completed runs from earlier versions,
 * the new column starts null until the consumer writes the next batch
 * — meaning the filter would silently exclude every pre-#118 run.
 *
 * This patch reads each row's `summary_json`, derives finding_count via
 * Report\FindingCountExtractor (pure pipeline, unit-tested), and writes
 * the scalar. Rows where extraction returns null (malformed JSON, error
 * envelope, queued/running with no JSON yet) are left as null — those
 * legitimately have "no count" and the filter pane treats null as
 * "outside any window", which matches admin intent (you don't filter
 * incomplete runs by count).
 *
 * Idempotent: re-running rewrites the same value. Safe to revert.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Setup\Patch\Data;

use IronCart\Scan\Report\FindingCountExtractor;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class BackfillFindingCounts implements DataPatchInterface
{
    private const TABLE = 'ironcart_scan_run';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable(self::TABLE);

        // Only consider rows that have summary_json populated AND
        // finding_count still null. New rows from the consumer set
        // finding_count directly, so we don't want to clobber them on
        // a no-op re-apply.
        $select = $connection->select()
            ->from($table, ['entity_id', 'summary_json'])
            ->where('summary_json IS NOT NULL')
            ->where('finding_count IS NULL');

        $rows = $connection->fetchAll($select);
        foreach ($rows as $row) {
            $count = FindingCountExtractor::fromSummaryJson(
                isset($row['summary_json']) ? (string)$row['summary_json'] : null
            );
            if ($count === null) {
                continue;
            }
            $connection->update(
                $table,
                ['finding_count' => $count],
                ['entity_id = ?' => (int)$row['entity_id']]
            );
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function getAliases(): array
    {
        return [];
    }
}
