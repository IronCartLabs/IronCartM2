<?php

/**
 * IronCart_Scan — async scan run consumer.
 *
 * Handler bound to the `ironcart.scan.run` topic via
 * etc/communication.xml + etc/queue_consumer.xml. The framework
 * delivers a JSON-string payload; the consumer rehydrates it into a
 * ScanRunMessage, loads the matching ScanRun row, drives the existing
 * CheckRegistry (the same engine `bin/magento ironcart:scan` uses —
 * AC explicitly forbids duplicating check logic), and persists
 * findings + terminal status.
 *
 * Failure-mode contract:
 *   - On any throwable: status -> failed, summary_json carries
 *     `{ "error": { "class": ..., "message": ... } }`, finished_at set,
 *     and the exception is logged via Psr\Log\LoggerInterface.
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
use IronCart\Scan\Check\CheckRegistry;
use IronCart\Scan\Model\Message\ScanRunMessage;
use IronCart\Scan\Model\ResourceModel\ScanFinding as ScanFindingResource;
use IronCart\Scan\Model\ResourceModel\ScanRun as ScanRunResource;
use IronCart\Scan\Report\ReportBuilder;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Throwable;

class ScanRunConsumer
{
    public function __construct(
        private readonly ScanRunFactory $scanRunFactory,
        private readonly ScanRunResource $scanRunResource,
        private readonly ScanFindingFactory $scanFindingFactory,
        private readonly ScanFindingResource $scanFindingResource,
        private readonly CheckRegistry $checkRegistry,
        private readonly ReportBuilder $reportBuilder,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly Json $serializer,
        private readonly LoggerInterface $logger
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

        try {
            $this->runScan($run);
        } catch (Throwable $e) {
            $this->markFailed($run, $e);
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

        $findings = $this->checkRegistry->runAll();

        $report = $this->reportBuilder->build(
            magentoVersion: $this->productMetadata->getVersion(),
            magentoEdition: $this->productMetadata->getEdition(),
            findings: $findings
        );

        // Persist the decorated findings (the v1 ReportBuilder enriches
        // deprecated check ids with `deprecated_in` / `removal_in` /
        // `replacement` / `migration_url`) so the admin scan-run grid
        // can render the [deprecated] badge from a single per-finding
        // source of truth — issue #83.
        $runId = (int)$run->getId();
        foreach ($report['findings'] as $finding) {
            $this->persistFinding($runId, $finding);
        }

        $finishedAt = $this->nowUtc();
        $run->setStatus(ScanRun::STATUS_SUCCEEDED);
        $run->setFinishedAt($finishedAt);
        $run->setSummaryJson($this->serializer->serialize([
            'totals' => $report['summary'],
            'finding_count' => count($findings),
            'magento' => $report['magento'],
            'schema_version' => $report['schema_version'],
        ]));
        // Defense-in-depth (#76): fail loud if a future refactor lets a
        // terminal status reach the save without finished_at being set.
        ScanRunTerminalState::assertConsistent(ScanRun::STATUS_SUCCEEDED, $finishedAt);
        $this->scanRunResource->save($run);
    }

    /**
     * Persist a single finding row.
     *
     * The optional v1 deprecation fields (issue #83) — `deprecated_in`,
     * `removal_in`, `replacement`, `migration_url` — are folded into the
     * `evidence_json` payload under a `deprecation` envelope so the admin
     * scan-run UI can surface the `[deprecated]` badge without a schema
     * column. The presence/absence of the envelope is the single source
     * of truth for "this row is deprecated" in admin renderings.
     *
     * @param array<string,mixed> $finding canonical finding shape (id, title,
     *     severity, evidence, remediation_url) optionally with the v1
     *     deprecation keys when the id is registered in
     *     {@see \IronCart\Scan\Check\DeprecationRegistry}.
     */
    private function persistFinding(int $runId, array $finding): void
    {
        $row = $this->scanFindingFactory->create();
        $row->setScanRunId($runId);
        $row->setCheckId((string)$finding['id']);
        $row->setSeverity((string)$finding['severity']);
        $row->setTitle((string)$finding['title']);
        $row->setDetail(null);

        $evidence = $finding['evidence'] ?? null;
        $hasDeprecation = isset($finding['deprecated_in']);

        if ($evidence === null && !$hasDeprecation) {
            $row->setEvidenceJson(null);
        } else {
            $payload = [
                'evidence' => $evidence,
                'remediation_url' => $finding['remediation_url'] ?? '',
            ];
            if ($hasDeprecation) {
                $payload['deprecation'] = [
                    'deprecated_in' => (string)$finding['deprecated_in'],
                    'removal_in' => (string)($finding['removal_in'] ?? ''),
                    'replacement' => (string)($finding['replacement'] ?? ''),
                    'migration_url' => (string)($finding['migration_url'] ?? ''),
                ];
            }
            $row->setEvidenceJson($this->serializer->serialize($payload));
        }
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
