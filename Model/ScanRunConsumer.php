<?php

/**
 * IronCart_Scan — async scan run consumer.
 *
 * Handler bound to the `ironcart.scan.run` topic via
 * etc/communication.xml + etc/queue_consumer.xml. The framework
 * delivers a JSON-string payload; the consumer rehydrates it into a
 * ScanRunMessage, loads the matching ScanRun row, drives the shared
 * {@see ScanEngineRunner} (the same engine `bin/magento ironcart:scan`
 * uses — AC explicitly forbids duplicating check logic), and persists
 * findings + terminal status.
 *
 * Concurrency contract (IronCartLabs/IronCartM2#155):
 *   - On entry the handler try-locks {@see self::LOCK_NAME} with a
 *     0-second timeout. This is the SAME named lock that
 *     {@see \IronCart\Scan\Cron\DrainScanConsumer} contends on, so any
 *     driver of this consumer — our cron job, an operator-run
 *     `queue:consumers:start` supervisor, OR Magento core's built-in
 *     `consumers_runner` cron group — passes through one collapse
 *     point. If two consumer PROCESSES happen to claim different
 *     messages out of the DB queue at the same minute, the second one
 *     bounces its message back onto the topic instead of running
 *     `scanEngineRunner->runAndReport()` in parallel.
 *   - The bounced message keeps its original payload; the run row
 *     stays at `queued`. The bounce-retry budget is naturally bounded
 *     because the racing consumer holds the lock for at most one scan
 *     (typically a handful of seconds), after which the next tick
 *     succeeds. We DON'T track a republish counter — the DB queue's
 *     row-locking guarantees each message goes to exactly one
 *     consumer per attempt, so the bounce loop self-terminates.
 *
 * Failure-mode contract:
 *   - On any throwable inside `runScan()`: status -> failed,
 *     summary_json carries `{ "error": { "class": ..., "message": ... } }`,
 *     finished_at set, and the exception is logged via
 *     Psr\Log\LoggerInterface.
 *   - The throwable is intentionally swallowed at the handler boundary
 *     so the queue worker keeps draining. Re-raising would mark the
 *     queue message as failed and trigger framework-level retries,
 *     which is the wrong shape — Ironcart scan failures are deterministic
 *     (broken check / unreachable filesystem path) and won't recover
 *     on retry.
 *
 * Read-only posture: the consumer must not make outbound network
 * calls. Every check it drives is already read-only by module-wide
 * v0 contract; this handler only adds DB writes for the run row +
 * findings.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Model;

use DateTimeImmutable;
use DateTimeZone;
use IronCart\Scan\Model\Message\ScanRunMessage;
use IronCart\Scan\Model\ResourceModel\ScanFinding as ScanFindingResource;
use IronCart\Scan\Model\ResourceModel\ScanRun as ScanRunResource;
use IronCart\Scan\Report\FindingDetailFormatter;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Throwable;

class ScanRunConsumer
{
    /**
     * Named lock that gates concurrent execution of {@see runScan()} across
     * every driver of this consumer — module-owned cron drain, operator
     * supervisor, AND Magento core's `consumers_runner` group. The value
     * matches {@see \IronCart\Scan\Cron\DrainScanConsumer::LOCK_NAME} so the
     * two paths collapse onto the same Magento lock provider row.
     *
     * Duplicated as a literal (rather than referencing
     * `DrainScanConsumer::LOCK_NAME` directly) to keep `Model\` independent
     * of `Cron\`; the Magento-free `Test/Unit/Report/ConsumerLockNameShapeTest`
     * pins both constants to the same string via source-file regex so
     * drift fails CI under the unit cell.
     */
    public const LOCK_NAME = 'ironcart_scan_consumer_drain';

    public function __construct(
        private readonly ScanRunFactory $scanRunFactory,
        private readonly ScanRunResource $scanRunResource,
        private readonly ScanFindingFactory $scanFindingFactory,
        private readonly ScanFindingResource $scanFindingResource,
        private readonly ScanEngineRunner $scanEngineRunner,
        private readonly Json $serializer,
        private readonly LoggerInterface $logger,
        private readonly FindingDetailFormatter $detailFormatter,
        private readonly LockManagerInterface $lockManager,
        private readonly PublisherInterface $publisher
    ) {
    }

    /**
     * Topic handler. Magento's MessageQueue framework deserialises the
     * `request="string"` declared in communication.xml into a string
     * argument, so the handler signature here is a plain `string $body`.
     *
     * @param string $body JSON-encoded ScanRunMessage payload
     */
    public function process(string $body): void
    {
        try {
            $payload = $this->serializer->unserialize($body);
        } catch (Throwable $e) {
            // Bad JSON → can't even locate the run row to mark it failed.
            // Log and drop; the queue framework will not retry.
            $this->logger->error(
                'IronCart_Scan: dropping malformed ironcart.scan.run message',
                ['exception' => $e]
            );
            return;
        }

        if (!is_array($payload)) {
            $this->logger->error(
                'IronCart_Scan: ironcart.scan.run payload is not an object',
                ['payload_type' => gettype($payload)]
            );
            return;
        }

        try {
            $message = ScanRunMessage::fromArray($payload);
        } catch (Throwable $e) {
            $this->logger->error(
                'IronCart_Scan: ironcart.scan.run payload failed validation',
                ['exception' => $e, 'payload' => $payload]
            );
            return;
        }

        $run = $this->scanRunFactory->create();
        $this->scanRunResource->load($run, $message->getScanRunId());
        if (!$run->getId()) {
            $this->logger->error(
                'IronCart_Scan: ironcart.scan.run references a missing run row',
                ['scan_run_id' => $message->getScanRunId()]
            );
            return;
        }

        // Race close (#155). Magento core's `consumers_runner` cron group
        // and our `ironcart_scan_consumer_drain` cron job can both spawn
        // the `ironcartScanRunConsumer` consumer once per minute. The
        // DB queue's row-locking guarantees each *message* goes to
        // exactly one consumer per attempt, but two consumer PROCESSES
        // claiming two *different* messages at the same minute would
        // otherwise run `runScan()` in parallel and double the
        // wall-clock cost of the file-integrity walk. Try-lock with a
        // 0s timeout so we fail fast: if the other process holds the
        // lock, bounce this message back onto the topic and ACK so
        // the queue framework doesn't mark it failed.
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            $this->logger->info(
                'IronCart_Scan: scan-run handler skipped — drain lock held'
                . ' by another consumer process; re-publishing message.',
                [
                    'lock_name' => self::LOCK_NAME,
                    'scan_run_id' => $message->getScanRunId(),
                ]
            );
            try {
                // Same payload — the message envelope (ScanRunMessage)
                // carries no per-attempt state, so a republish is
                // byte-identical to the original publish. The DB queue
                // row-locks the new copy independently of the message
                // we are about to ACK by returning.
                $this->publisher->publish(ScanRunPublisher::TOPIC, $body);
            } catch (Throwable $republishError) {
                // If even the republish blew up (DB unreachable, etc.)
                // we cannot leave the row stuck at `queued` forever —
                // the stuck-QUEUED admin notice (#92) would eventually
                // fire, but failing loudly is better than silent
                // staleness.
                $this->markFailed($run, $republishError);
            }
            return;
        }

        try {
            $this->runScan($run);
        } catch (Throwable $e) {
            $this->markFailed($run, $e);
        } finally {
            // Always release the lock — a poison message must not
            // brick every subsequent tick by leaving the lock held.
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /**
     * Drive the existing scan engine and persist findings + summary.
     */
    private function runScan(ScanRun $run): void
    {
        $now = $this->nowUtc();

        $run->setStatus(ScanRun::STATUS_RUNNING);
        $run->setStartedAt($now);
        $this->scanRunResource->save($run);

        $result = $this->scanEngineRunner->runAndReport();
        $findings = $result->findings;
        $report = $result->report;

        $runId = (int)$run->getId();
        foreach ($findings as $finding) {
            $this->persistFinding($runId, $finding);
        }

        $finishedAt = $this->nowUtc();
        $findingCount = count($findings);
        $run->setStatus(ScanRun::STATUS_SUCCEEDED);
        $run->setFinishedAt($finishedAt);
        $run->setSummaryJson($this->serializer->serialize([
            'totals' => $report['summary'],
            'finding_count' => $findingCount,
            'magento' => $report['magento'],
            'schema_version' => $report['schema_version'],
        ]));
        // Mirror finding_count into a scalar column so the admin grid
        // (issue #118) can pushdown a numeric-range filter. The JSON
        // payload stays the source of truth; this column is denormalised
        // for filter pushdown only.
        $run->setData('finding_count', $findingCount);
        // Defense-in-depth (#76): fail loud if a future refactor lets a
        // terminal status reach the save without finished_at being set.
        ScanRunTerminalState::assertConsistent(ScanRun::STATUS_SUCCEEDED, $finishedAt);
        $this->scanRunResource->save($run);
    }

    /**
     * Persist a single finding row.
     *
     * @param array{
     *     id:string,
     *     title:string,
     *     severity:string,
     *     evidence:mixed,
     *     remediation_url:string
     * } $finding
     */
    private function persistFinding(int $runId, array $finding): void
    {
        $row = $this->scanFindingFactory->create();
        $row->setScanRunId($runId);
        $row->setCheckId((string)$finding['id']);
        $row->setSeverity((string)$finding['severity']);
        $row->setTitle((string)$finding['title']);

        $evidence = $finding['evidence'] ?? null;
        $remediationUrl = (string)($finding['remediation_url'] ?? '');

        // Compute the admin-grid `detail` string from evidence +
        // remediation URL. Per #107 AC: returns null when both are
        // empty so historical NULL rows continue to render empty
        // (no migration, no synthesised empty strings). Truncation is
        // owned by ScanFindingDataProvider::truncate() — do not pre-
        // truncate here.
        $row->setDetail($this->detailFormatter->format($evidence, $remediationUrl));

        $row->setEvidenceJson(
            $evidence === null ? null : $this->serializer->serialize([
                'evidence' => $evidence,
                'remediation_url' => $remediationUrl,
            ])
        );
        $this->scanFindingResource->save($row);
    }

    /**
     * Transition the run to `failed` and persist the error envelope.
     */
    private function markFailed(ScanRun $run, Throwable $e): void
    {
        $this->logger->error(
            'IronCart_Scan: ironcart.scan.run consumer raised an exception',
            ['scan_run_id' => $run->getId(), 'exception' => $e]
        );

        try {
            $finishedAt = $this->nowUtc();
            $run->setStatus(ScanRun::STATUS_FAILED);
            $run->setFinishedAt($finishedAt);
            $run->setSummaryJson($this->serializer->serialize([
                'error' => [
                    'class' => get_class($e),
                    'message' => $e->getMessage(),
                ],
            ]));
            // Defense-in-depth (#76): mirror the success path's invariant
            // assertion so the failed transition can never silently emit
            // a terminal row with an empty `finished` column.
            ScanRunTerminalState::assertConsistent(ScanRun::STATUS_FAILED, $finishedAt);
            $this->scanRunResource->save($run);
        } catch (Throwable $persistError) {
            // Last-ditch — we already lost the original error context above.
            $this->logger->critical(
                'IronCart_Scan: failed to record terminal status for scan run',
                ['scan_run_id' => $run->getId(), 'exception' => $persistError]
            );
        }
    }

    /**
     * UTC `YYYY-mm-dd HH:ii:ss` timestamp suitable for direct insert into
     * the `started_at` / `finished_at` columns (MariaDB TIMESTAMP).
     */
    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');
    }
}
