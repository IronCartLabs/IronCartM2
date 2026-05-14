<?php

/**
 * IronCart_Scan — IC-040 indexer state.
 *
 * Flags any indexer that has been in `invalid` / `reindex required` state for
 * longer than the configured threshold (24h by default). Stale indexers are a
 * frequent operational root cause of stale ACL caches, stale catalog pricing,
 * and stale customer-segment grants — so we surface them in the security scan.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Operational;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\Finding;
use IronCart\Scan\Report\Severity;
use Magento\Framework\Indexer\ConfigInterface as IndexerConfig;
use Magento\Framework\Indexer\IndexerInterfaceFactory;
use Magento\Framework\Indexer\StateInterface;
use Throwable;

/**
 * IC-040 — flag indexers stuck in `invalid` for > N hours.
 */
class IndexerStateCheck implements CheckInterface
{
    public const ID = 'IC-040';

    /** Hours before a stuck indexer is considered stale. */
    public const STALE_HOURS = 24;

    public function __construct(
        private readonly IndexerConfig $indexerConfig,
        private readonly IndexerInterfaceFactory $indexerFactory
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function run(): array
    {
        $threshold = self::STALE_HOURS * 3600;
        $now = time();
        $stale = [];

        foreach ($this->indexerConfig->getIndexers() as $indexerId => $_config) {
            $indexer = $this->indexerFactory->create();
            try {
                $indexer->load($indexerId);
            } catch (Throwable) {
                continue;
            }

            $state = $indexer->getState();
            if (!$state instanceof StateInterface) {
                continue;
            }

            if ($state->getStatus() !== StateInterface::STATUS_INVALID) {
                continue;
            }

            $updatedAt = (string) $state->getUpdated();
            $updatedTs = $updatedAt !== '' ? strtotime($updatedAt) : false;
            if ($updatedTs === false) {
                continue;
            }

            $age = $now - $updatedTs;
            if ($age < $threshold) {
                continue;
            }

            $stale[] = [
                'indexer_id' => (string) $indexerId,
                'title' => (string) $indexer->getTitle(),
                'updated_at' => $updatedAt,
                'age_hours' => (int) floor($age / 3600),
            ];
        }

        if ($stale === []) {
            return [];
        }

        return [
            Finding::make(
                id: self::ID,
                title: sprintf(
                    '%d indexer(s) have been in "Reindex required" for > %dh',
                    count($stale),
                    self::STALE_HOURS
                ),
                severity: Severity::MEDIUM,
                evidence: [
                    'threshold_hours' => self::STALE_HOURS,
                    'indexers' => $stale,
                ],
                remediationUrl: 'https://developer.adobe.com/commerce/php/development/components/indexing/'
            ),
        ];
    }
}
