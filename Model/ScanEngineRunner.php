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
