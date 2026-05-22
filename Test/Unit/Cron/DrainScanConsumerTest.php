<?php

/**
 * IronCart_Scan — module-owned drain unit tests.
 *
 * Covers the invariants documented on IronCartLabs/IronCartM2#140:
 *
 *   - `ConsumerFactory::get()` is called with the `ironcartScanRunConsumer`
 *     handle so a typo on the consumer name can never silently make
 *     the cron a no-op.
 *   - When the named lock is already held (because a long-lived
 *     supervisor is draining the same queue), the handler logs a skip
 *     line and exits without touching the consumer factory.
 *   - When the consumer's process() throws, the handler catches and
 *     logs the exception, then releases the lock — it must NEVER
 *     re-throw, because a poison message must not freeze the whole
 *     `ironcart_scan` cron group.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Cron;

use IronCart\Scan\Cron\DrainScanConsumer;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\MessageQueue\ConsumerFactory;
use Magento\Framework\MessageQueue\ConsumerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \IronCart\Scan\Cron\DrainScanConsumer
 */
class DrainScanConsumerTest extends TestCase
{
    public function testHappyPathCallsConsumerFactoryWithExpectedHandle(): void
    {
        $consumer = $this->createMock(ConsumerInterface::class);
        $consumer->expects(self::once())
            ->method('process')
            ->with(DrainScanConsumer::MAX_MESSAGES);

        $factory = $this->createMock(ConsumerFactory::class);
        $factory->expects(self::once())
            ->method('get')
            ->with(DrainScanConsumer::CONSUMER_NAME, DrainScanConsumer::MAX_MESSAGES)
            ->willReturn($consumer);

        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects(self::once())
            ->method('lock')
            ->with(DrainScanConsumer::LOCK_NAME, 0)
            ->willReturn(true);
        $lockManager->expects(self::once())
            ->method('unlock')
            ->with(DrainScanConsumer::LOCK_NAME)
            ->willReturn(true);

        $logger = new RecordingLogger();

        $cron = new DrainScanConsumer($factory, $lockManager, $logger);
        $cron->execute();

        // The happy path is intentionally quiet — no skip log, no
        // exception log, no overrun warning (process() returned
        // instantly in the mock).
        self::assertSame([], $logger->lines, 'Happy path must not log anything: ' . print_r($logger->lines, true));
    }

    public function testLockHeldPathSkipsConsumerFactoryAndLogsOnce(): void
    {
        $factory = $this->createMock(ConsumerFactory::class);
        // CRITICAL: the lock-held path MUST NOT construct the consumer.
        // If it does, a supervisor and a cron tick will both pull from
        // the same DB queue at the same time and double-process scans.
        $factory->expects(self::never())->method('get');

        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects(self::once())
            ->method('lock')
            ->with(DrainScanConsumer::LOCK_NAME, 0)
            ->willReturn(false);
        // No unlock when we never acquired the lock — releasing
        // someone else's lock would defeat the whole point.
        $lockManager->expects(self::never())->method('unlock');

        $logger = new RecordingLogger();

        $cron = new DrainScanConsumer($factory, $lockManager, $logger);
        $cron->execute();

        self::assertCount(1, $logger->lines, 'Lock-held path must log exactly one line');
        self::assertSame('info', $logger->lines[0]['level']);
        self::assertStringContainsString('skipped', $logger->lines[0]['message']);
        self::assertSame(
            DrainScanConsumer::LOCK_NAME,
            $logger->lines[0]['context']['lock_name'] ?? null
        );
        self::assertSame(
            DrainScanConsumer::CONSUMER_NAME,
            $logger->lines[0]['context']['consumer'] ?? null
        );
    }

    public function testConsumerProcessExceptionIsCaughtAndLoggedAndLockIsReleased(): void
    {
        $consumer = $this->createMock(ConsumerInterface::class);
        $consumer->method('process')->willThrowException(new RuntimeException('boom'));

        $factory = $this->createMock(ConsumerFactory::class);
        $factory->method('get')->willReturn($consumer);

        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        // The lock MUST be released even on the exception path —
        // otherwise a single bad message would brick every subsequent
        // tick forever.
        $lockManager->expects(self::once())
            ->method('unlock')
            ->with(DrainScanConsumer::LOCK_NAME);

        $logger = new RecordingLogger();

        $cron = new DrainScanConsumer($factory, $lockManager, $logger);

        // Must NOT re-throw — Magento would otherwise mark the
        // schedule row as `error` and the operator would have a noisy
        // failure every minute.
        $cron->execute();

        $errorLines = array_filter(
            $logger->lines,
            static fn(array $l): bool => $l['level'] === 'error'
        );
        self::assertCount(1, $errorLines, 'Exception path must log exactly one error line');
        $line = array_values($errorLines)[0];
        self::assertStringContainsString('failed', $line['message']);
        self::assertInstanceOf(RuntimeException::class, $line['context']['exception'] ?? null);
        self::assertSame(
            DrainScanConsumer::CONSUMER_NAME,
            $line['context']['consumer'] ?? null
        );
    }

    public function testConstantsMatchPublishedQueueConsumerName(): void
    {
        // Belt-and-braces. The consumer name lives in three places
        // (etc/queue_consumer.xml, this handler, the README) and the
        // string MUST match `etc/queue_consumer.xml` exactly or the
        // ConsumerFactory throws and the cron logs an error forever.
        self::assertSame('ironcartScanRunConsumer', DrainScanConsumer::CONSUMER_NAME);
        self::assertSame('ironcart_scan_consumer_drain', DrainScanConsumer::LOCK_NAME);
        self::assertLessThan(60, DrainScanConsumer::MAX_RUNTIME_SECONDS, 'Wall-clock budget must be < 60s so one tick cannot overlap the next');
        self::assertGreaterThan(0, DrainScanConsumer::MAX_MESSAGES);
    }

    public function testMaxMessagesIsOneSoEachTickHandlesAtMostOneScan(): void
    {
        // Regression guard for IronCartLabs/IronCartM2#160. Each
        // `ironcart.scan.run` message drives the full check registry
        // (5–30s wall-clock on a moderate Magento install) and holds
        // {@see DrainScanConsumer::LOCK_NAME} for the duration. If a
        // future refactor restores the burst behaviour (e.g. MAX_MESSAGES
        // = 100) the lock would be held for 500–3000s, every subsequent
        // cron tick in the minute cadence would no-op on the
        // lock-held path, and the 60-second freshness threshold in
        // ConsumerStalledPredicate would misfire while the queue drains.
        //
        // The right lever for operators with a persistent backlog is the
        // Option A supervisor (long-lived
        // `bin/magento queue:consumers:start ironcartScanRunConsumer`),
        // NOT cranking this constant up.
        self::assertSame(
            1,
            DrainScanConsumer::MAX_MESSAGES,
            'MAX_MESSAGES must stay 1 — see IronCartLabs/IronCartM2#160. ' .
            'If you need higher throughput, run the Option A supervisor instead of ' .
            'increasing this constant; the lock-hold expectations elsewhere in the ' .
            'module (ConsumerStalledPredicate freshness threshold, cron tick cadence) ' .
            'assume per-tick = per-scan.'
        );
    }
}
