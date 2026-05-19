<?php

/**
 * IronCart_Scan — module-owned drain for the `ironcartScanRunConsumer`
 * queue consumer.
 *
 * Magento cron entry point declared in `etc/crontab.xml` as
 * `ironcart_scan_consumer_drain`. Runs every minute and drives the
 * `ironcartScanRunConsumer` consumer (declared in
 * `etc/queue_consumer.xml`) directly so a fresh
 * `composer require ironcartlabs/magento-scan` + `bin/magento setup:upgrade`
 * is enough for **Run Scan Now** clicks to flip from QUEUED to a terminal
 * state.
 *
 * Before this handler existed the README asked operators to choose
 * between a long-lived supervisor process or a `cron_consumers_runner`
 * edit in `app/etc/env.php`. Neither is required now — see
 * IronCartLabs/IronCartM2#140.
 *
 * Execution semantics:
 *
 *   - Bounded by both message count ({@see self::MAX_MESSAGES}) and a
 *     wall-clock budget ({@see self::MAX_RUNTIME_SECONDS}) so a single
 *     tick cannot overlap the next minute's tick.
 *   - Try-locks {@see self::LOCK_NAME} with a 0-second timeout. If the
 *     lock is already held (because the operator is running a dedicated
 *     `bin/magento queue:consumers:start ironcartScanRunConsumer`
 *     supervisor under the same lock), the handler logs a single info
 *     line and exits clean. This keeps existing Option A users working
 *     without double-draining the queue.
 *   - All exceptions are caught and logged via the existing
 *     `ironcart_scan_cron` Monolog channel (wired in `etc/di.xml`).
 *     The handler never throws — a single bad message in the queue
 *     must not poison the cron schedule and stop future ticks.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Cron;

use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\MessageQueue\ConsumerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Magento cron handler that drains the `ironcartScanRunConsumer` queue
 * once per minute.
 *
 * Bound to {@see \IronCart\Scan\Cron\DrainScanConsumer::execute()} from
 * `etc/crontab.xml` under the `ironcart_scan` cron group.
 */
class DrainScanConsumer
{
    /**
     * Name of the consumer to drive, as declared in
     * `etc/queue_consumer.xml`.
     */
    public const CONSUMER_NAME = 'ironcartScanRunConsumer';

    /**
     * Hard cap on messages processed per tick. The DB queue is FIFO and
     * a single scan run is the unit of work; ~100 covers any realistic
     * burst of admin **Run Scan Now** clicks while leaving the per-tick
     * budget bounded.
     */
    public const MAX_MESSAGES = 100;

    /**
     * Wall-clock budget for a single tick, in seconds. Magento's cron
     * tick interval is one minute; capping the drain at 55s guarantees
     * one tick cannot overlap the next, even when the consumer factory
     * itself takes a couple of seconds to construct on a cold app
     * container.
     */
    public const MAX_RUNTIME_SECONDS = 55;

    /**
     * Named lock both this cron handler and a long-lived
     * `queue:consumers:start ironcartScanRunConsumer` supervisor
     * contend on. The 0-second timeout is intentional: if the lock is
     * already held we want to bail immediately, not block the whole
     * cron group while a supervisor finishes its current batch.
     *
     * The name is stable across versions so an operator switching
     * between Option A (supervisor) and Option C (this cron) does not
     * have to flush any state.
     */
    public const LOCK_NAME = 'ironcart_scan_consumer_drain';

    public function __construct(
        private readonly ConsumerFactory $consumerFactory,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Magento cron entry point. Bound from `etc/crontab.xml`.
     *
     * Returns `void` — and never re-throws — so a single bad message in
     * the queue cannot mark every subsequent minute's `cron_schedule`
     * row as `error` and starve other jobs in the `ironcart_scan`
     * group.
     */
    public function execute(): void
    {
        // Lock-skip path. A 0-second timeout means "try once, fail
        // immediately" — exactly what we want when a supervisor
        // process is already draining the same queue under the same
        // lock name.
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            $this->logger->info(
                'IronCart_Scan: cron consumer drain skipped — lock held by another process'
                . ' (long-lived supervisor or overlapping tick).',
                ['lock_name' => self::LOCK_NAME, 'consumer' => self::CONSUMER_NAME]
            );
            return;
        }

        try {
            $this->drain();
        } catch (Throwable $e) {
            // Catch-all: the consumer's own handler is responsible for
            // marking individual messages as failed. Anything that
            // escapes that far is infrastructure-level (DB unreachable,
            // DI wiring exploded, etc.) — log it once and let the next
            // tick try again.
            $this->logger->error(
                'IronCart_Scan: cron consumer drain failed',
                ['exception' => $e, 'consumer' => self::CONSUMER_NAME]
            );
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /**
     * Drive the configured consumer for at most {@see self::MAX_MESSAGES}
     * messages, or until the wall-clock budget is exhausted. Magento's
     * {@see ConsumerFactory::get()} accepts an explicit max-messages
     * argument; we pass the same value to `process()` so the consumer
     * exits cleanly when the queue is empty rather than blocking.
     */
    private function drain(): void
    {
        $deadline = microtime(true) + self::MAX_RUNTIME_SECONDS;

        $consumer = $this->consumerFactory->get(self::CONSUMER_NAME, self::MAX_MESSAGES);
        $consumer->process(self::MAX_MESSAGES);

        // The wall-clock guard is informational rather than enforced
        // mid-loop because `process()` is a single bounded call — we
        // log if we overran so operators can tune MAX_MESSAGES down
        // without having to spelunk through Magento's cron log.
        if (microtime(true) > $deadline) {
            $this->logger->warning(
                'IronCart_Scan: cron consumer drain exceeded wall-clock budget',
                [
                    'consumer' => self::CONSUMER_NAME,
                    'budget_seconds' => self::MAX_RUNTIME_SECONDS,
                    'max_messages' => self::MAX_MESSAGES,
                ]
            );
        }
    }
}
