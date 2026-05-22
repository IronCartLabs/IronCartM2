<?php

/**
 * IronCart_Scan — scan-engine result envelope.
 *
 * Value object returned by {@see ScanEngineRunner::runAndReport()}. Carries
 * the flat finding list, the assembled v0 report array, and the Magento
 * version + edition that were read once at the top of the run. Three
 * call-sites (`Console\Command\ScanCommand`, `Cron\UploadScan`,
 * `Model\ScanRunConsumer`) previously re-encoded the same orchestration
 * shape inline — see IronCartLabs/IronCartM2#156 for the extraction
 * justification (CLAUDE.md's 3-uses threshold).
 *
 * Pure-data: no Magento types, no behaviour. Living under Model/ rather
 * than Report/ because it belongs to the engine-orchestration surface,
 * not the JSON-shape surface owned by {@see \IronCart\Scan\Report\ReportBuilder}.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Model;

/**
 * Immutable envelope carrying everything a scan-engine caller needs.
 */
final class ScanEngineResult
{
    /**
     * @param list<array{
     *     id:string,
     *     title:string,
     *     severity:string,
     *     evidence:mixed,
     *     remediation_url:string
     * }> $findings
     * @param array{
     *     schema_version:string,
     *     generated_at:string,
     *     magento:array{version:string,edition:string},
     *     summary:array<string,int>,
     *     findings:list<array{id:string,title:string,severity:string,evidence:mixed,remediation_url:string}>
     * } $report
     */
    public function __construct(
        public readonly array $findings,
        public readonly array $report,
        public readonly string $magentoVersion,
        public readonly string $magentoEdition
    ) {
    }
}
