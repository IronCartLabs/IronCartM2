<?php

/**
 * IronCart_Scan — IC-043 message-queue backlog.
 *
 * Iterates the Magento MessageQueue topology and flags any queue whose depth
 * exceeds 10k messages. A runaway queue is rarely a security finding on its
 * own but it routinely correlates with broken consumers — and broken consumers
 * mean async security tasks (re-index after permission change, customer-data
 * scrub, etc.) silently never run.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Operational;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\Finding;
use IronCart\Scan\Report\Severity;
use Magento\MysqlMq\Model\ResourceModel\Queue\CollectionFactory as QueueCollectionFactory;

/**
 * IC-043 — flag message-queue backlogs that exceed the depth threshold.
 */
class MessageQueueBacklogCheck implements CheckInterface
{
    public const ID = 'IC-043';

    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-043';

    /** Depth threshold per queue. */
    public const DEPTH_THRESHOLD = 10000;

    /**
     * @param QueueCollectionFactory|null $queueCollectionFactory Optional: only present
     *                                                            when Magento_MysqlMq is
     *                                                            installed (the default).
     */
    public function __construct(
        private readonly ?QueueCollectionFactory $queueCollectionFactory = null
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function run(): array
    {
        if ($this->queueCollectionFactory === null) {
            // Magento_MysqlMq is not installed (operator runs RabbitMQ only).
            // v0 limits itself to read-only checks against Magento's local DB;
            // a v3+ check can add an explicit RabbitMQ adapter.
            return [];
        }

        $collection = $this->queueCollectionFactory->create();
        $over = [];

        foreach ($collection as $queue) {
            // The MysqlMq queue model exposes name + the related messages
            // collection via getMessages(). Use the collection's getSize() —
            // it issues a COUNT(*) under the hood and avoids hydrating the
            // payload column for thousands of rows.
            $name = method_exists($queue, 'getName') ? (string) $queue->getName() : '';
            if ($name === '') {
                continue;
            }

            $depth = 0;
            if (method_exists($queue, 'getMessages')) {
                $messages = $queue->getMessages();
                if (is_object($messages) && method_exists($messages, 'getSize')) {
                    $depth = (int) $messages->getSize();
                }
            }

            if ($depth > self::DEPTH_THRESHOLD) {
                $over[] = [
                    'queue' => $name,
                    'depth' => $depth,
                ];
            }
        }

        if ($over === []) {
            return [];
        }

        return [
            Finding::make(
                id: self::ID,
                title: sprintf(
                    '%d message queue(s) over the %d-message backlog threshold',
                    count($over),
                    self::DEPTH_THRESHOLD
                ),
                severity: Severity::MEDIUM,
                evidence: [
                    'threshold' => self::DEPTH_THRESHOLD,
                    'queues' => $over,
                ],
                remediationUrl: self::REMEDIATION_URL
            ),
        ];
    }
}
