<?php

/**
 * IronCart_Scan — scan-engine orchestrator.
 *
 * Wraps the `CheckRegistry::runAll()` + `ReportBuilder::build()` +
 * `ProductMetadataInterface` triple that three call-sites previously
 * re-encoded inline:
 *
 *   - {@see \IronCart\Scan\Console\Command\ScanCommand} (CLI)
 *   - {@see \IronCart\Scan\Cron\UploadScan} (cron upload — `findings` only)
 *   - {@see \IronCart\Scan\Model\ScanRunConsumer} (async queue consumer)
 *
 * CLAUDE.md's anti-abstraction rule defers extraction until a pattern
 * hits 3+ uses. As of IronCartLabs/IronCartM2#156 it has, so this
 * runner consolidates the orchestration into one DI dep and one call.
 * Callers shrink from three constructor args (CheckRegistry +
 * ReportBuilder + ProductMetadataInterface) to one (this runner).
 *
 * Behaviour is intentionally identical to the previous inline shape —
 * this is a pure duplication-elimination ticket. The lifecycle question
 * (separate cron/consumer/CLI runs that all reach `runAll()` at the
 * same minute) is owned by a separate issue and not touched here.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Model;

use IronCart\Scan\Check\CheckRegistry;
use IronCart\Scan\Report\ReportBuilder;
use Magento\Framework\App\ProductMetadataInterface;

/**
 * Orchestrates a single scan + report-assembly pass.
 */
class ScanEngineRunner
{
    public function __construct(
        private readonly CheckRegistry $checkRegistry,
        private readonly ReportBuilder $reportBuilder,
        private readonly ProductMetadataInterface $productMetadata
    ) {
    }

    /**
     * Run every registered check and assemble the v0 report payload.
     *
     * Magento version + edition are read once at the top of the run and
     * carried on the result envelope so callers that need only the
     * findings (e.g. {@see \IronCart\Scan\Cron\UploadScan}) can skip the
     * `$result->report` field without re-querying ProductMetadata.
     *
     * ## Scan-execution callers (#150)
     *
     * This method is the shared scan engine for THREE intentionally-forked
     * execution lifecycles. Each caller owns a different combination of
     * (persists `ironcart_scan_run` row?, uploads to ironcart.dev?). They
     * are documented exhaustively in `docs/scan-execution-lifecycle.md`
     * and pinned by `Test/Unit/Report/ScanExecutionForkTest`. Do NOT add a
     * fourth caller without updating both — the fork test fails loudly if
     * you do, mirroring the pattern {@see ScanRunTerminalState} uses for
     * the (status, finished_at) invariant.
     *
     *   1. {@see \IronCart\Scan\Console\Command\ScanCommand::execute()}
     *      — CLI. No `scan_run` row. Upload only when `--upload` is
     *      passed. Lifecycle: synchronous, in-process; the exit code is
     *      the entire surface.
     *   2. {@see \IronCart\Scan\Cron\UploadScan::execute()}
     *      — Cron (continuous-monitoring). No `scan_run` row. Always
     *      uploads (the entire point of this entry point). Lifecycle:
     *      gated by `ironcart_scan/cron/enabled`; surface is the cron
     *      framework's `cron_schedule` row + var/log/cron.log.
     *   3. {@see \IronCart\Scan\Model\ScanRunConsumer::runScan()}
     *      — DB-queue consumer driven by "Run Scan Now" / admin enqueue
     *      via {@see \IronCart\Scan\Model\ScanRunPublisher}. Writes a
     *      `scan_run` row (queued -> running -> succeeded|failed).
     *      Never uploads. Lifecycle: surfaces in the admin grid only.
     *      `::process()` is the MQ topic entry point; it deserialises
     *      the payload, takes the drain lock (#155), then delegates
     *      to the private `runScan()` which is where this engine
     *      method is actually invoked.
     *
     * The fork is intentional, mirroring the IronCartWeb v4-vs-Recon
     * state-machine fork — see
     * `IronCartWeb/.claude/memory/reference_finding_state_machine_dual_impl.md`
     * and the M2-side companion note
     * `reference_m2_scan_execution_forks.md`. Collapsing the three
     * callers into a single orchestrator above this method is
     * explicitly out of scope until a fourth concrete consumer
     * materialises — see `docs/scan-execution-lifecycle.md` for the
     * rationale and the "when to add a fourth caller" checklist.
     */
    public function runAndReport(): ScanEngineResult
    {
        $findings = $this->checkRegistry->runAll();

        $magentoVersion = $this->productMetadata->getVersion();
        $magentoEdition = $this->productMetadata->getEdition();

        $report = $this->reportBuilder->build(
            magentoVersion: $magentoVersion,
            magentoEdition: $magentoEdition,
            findings: $findings
        );

        return new ScanEngineResult(
            findings: $findings,
            report: $report,
            magentoVersion: $magentoVersion,
            magentoEdition: $magentoEdition
        );
    }
}
