<?php

/**
 * IronCart_Scan — scan run publisher.
 *
 * Single entry point for enqueuing an async Ironcart scan. Creates the
 * `ironcart_scan_run` row up-front (status=queued) so the admin grid
 * shows the pending run immediately, then publishes the
 * `ironcart.scan.run` topic with the new row id. The DB queue consumer
 * picks it up and drives the existing CheckRegistry.
 *
 * Issue note: the AC originally proposed namespace
 * `IronCartLabs\MagentoScan\Model\ScanRunPublisher`. The module's PSR-4
 * root is `IronCart\Scan\` (see composer.json + every existing file
 * under Check/, Console/, Report/, etc.), so this class lives at
 * `IronCart\Scan\Model\ScanRunPublisher`. Renaming the PSR-4 root is
 * a much larger surgery and explicitly out of scope for this issue.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Model;

use IronCart\Scan\Model\Message\ScanRunMessage;
use IronCart\Scan\Model\ResourceModel\ScanRun as ScanRunResource;
use IronCart\Scan\Model\ScanRunFactory;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Enqueues a scan run by creating its row and publishing the topic
 * message.
 */
class ScanRunPublisher
{
    /**
     * Topic name — must match etc/communication.xml + queue_topology.xml.
     */
    public const TOPIC = 'ironcart.scan.run';

    public function __construct(
        private readonly ScanRunFactory $scanRunFactory,
        private readonly ScanRunResource $scanRunResource,
        private readonly PublisherInterface $publisher,
        private readonly Json $serializer
    ) {
    }

    /**
     * Create a queued ScanRun row and publish the run-id to the queue.
     *
     * @param string $triggeredBy `admin:<id>`, `cron`, or `cli` — see ScanRun::TRIGGER_*
     *
     * @return int The new `ironcart_scan_run.entity_id` value.
     */
    public function publish(string $triggeredBy): int
    {
        $run = $this->scanRunFactory->create();
        $run->setStatus(ScanRun::STATUS_QUEUED);
        $run->setTriggeredBy($triggeredBy);
        // `started_at` carries `CURRENT_TIMESTAMP` by default on the
        // DB column; leaving it unset means the queued-row timestamp
        // is the enqueue time. The consumer overwrites it with the
        // real start time on the `queued -> running` transition.
        $this->scanRunResource->save($run);

        $runId = (int)$run->getId();

        $message = new ScanRunMessage($runId, $triggeredBy);
        $this->publisher->publish(
            self::TOPIC,
            $this->serializer->serialize($message->toArray())
        );

        return $runId;
    }
}
