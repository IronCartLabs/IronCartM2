<?php

/**
 * IronCart_Scan — report builder.
 *
 * Assembles the canonical v0 report data structure from a list of findings.
 * The shape produced here is the source of truth for the JSON schema described
 * in {@link https://github.com/IronCartLabs/IronCartM2/issues/2} — bumping
 * `SCHEMA_VERSION` requires a migration note.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Report;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Builds the canonical scan-report array shape.
 */
class ReportBuilder
{
    /**
     * Current report schema version. Bump together with a migration note.
     */
    public const SCHEMA_VERSION = 'v0';

    /**
     * Build a v0 report payload from the given Magento metadata and findings.
     *
     * @param string                                                                     $magentoVersion  e.g. "2.4.7-p3"
     * @param string                                                                     $magentoEdition  e.g. "Community"
     * @param list<array{id:string,title:string,severity:string,evidence:mixed,remediation_url:string}> $findings
     *
     * @return array{
     *     schema_version:string,
     *     generated_at:string,
     *     magento:array{version:string,edition:string},
     *     summary:array<string,int>,
     *     findings:list<array{id:string,title:string,severity:string,evidence:mixed,remediation_url:string}>
     * }
     */
    public function build(string $magentoVersion, string $magentoEdition, array $findings): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->format('Y-m-d\TH:i:s\Z'),
            'magento' => [
                'version' => $magentoVersion,
                'edition' => $magentoEdition,
            ],
            'summary' => $this->summarise($findings),
            'findings' => array_values($findings),
        ];
    }

    /**
     * Tally findings by severity. Always returns every severity key, even
     * those with a zero count, so downstream consumers can rely on the shape.
     *
     * @param list<array{severity:string}> $findings
     *
     * @return array<string,int>
     */
    private function summarise(array $findings): array
    {
        $summary = [];
        foreach (Severity::ALL as $severity) {
            $summary[$severity] = 0;
        }

        foreach ($findings as $finding) {
            $severity = $finding['severity'];
            if (is_string($severity) && Severity::isValid($severity)) {
                $summary[$severity]++;
            }
        }

        return $summary;
    }
}