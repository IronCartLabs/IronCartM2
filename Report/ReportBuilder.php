<?php

/**
 * IronCart_Scan — report builder.
 *
 * Assembles the canonical report data structure from a list of findings.
 * The shape produced here is the source of truth for the JSON schema described
 * in {@link https://github.com/IronCartLabs/IronCartM2/issues/2} — bumping
 * `SCHEMA_VERSION` requires a migration note.
 *
 * **v0 → v1 (issue #83, announce-before-remove for v5).**
 * v1 is purely additive over v0:
 *   - Each finding whose `id` is registered in {@see DeprecationRegistry}
 *     gains `deprecated_in`, `removal_in`, `replacement`, and
 *     `migration_url` keys. Non-deprecated findings are unchanged.
 *   - The top-level `summary` map gains a single new key `deprecated`
 *     carrying the count of findings flagged with `deprecated_in`.
 *
 * No existing key is removed, no existing value's type changes. v0-era
 * parsers in the hosted backend (issue #57 ingest pipeline) tolerate
 * unknown keys, so they keep accepting v1 reports unchanged. Parsers that
 * want to surface the deprecation UI opt in by reading the new fields.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Report;

use DateTimeImmutable;
use DateTimeZone;
use IronCart\Scan\Check\DeprecationRegistry;

/**
 * Builds the canonical scan-report array shape.
 */
class ReportBuilder
{
    /**
     * Current report schema version. v1 adds optional deprecation fields
     * to findings + a `deprecated` summary count; everything in v0 is
     * preserved byte-identical. Bump together with a migration note.
     */
    public const SCHEMA_VERSION = 'v1';

    /**
     * Summary key for the count of deprecated findings. Kept distinct
     * from the {@see Severity::ALL} severity buckets so a "show me
     * everything to migrate before v2.0.0" pass needs only one key.
     */
    public const SUMMARY_DEPRECATED_KEY = 'deprecated';

    public function __construct(
        private readonly ?DeprecationRegistry $deprecations = null
    ) {
    }

    /**
     * Build a v1 report payload from the given Magento metadata and findings.
     *
     * Each input finding is checked against the {@see DeprecationRegistry};
     * matching findings are decorated additively with the four
     * deprecation keys before being included in the returned `findings`
     * list. Findings whose id is not in the registry are passed through
     * untouched.
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
     *     findings:list<array<string,mixed>>
     * }
     */
    public function build(string $magentoVersion, string $magentoEdition, array $findings): array
    {
        $decorated = $this->decorate($findings);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->format('Y-m-d\TH:i:s\Z'),
            'magento' => [
                'version' => $magentoVersion,
                'edition' => $magentoEdition,
            ],
            'summary' => $this->summarise($decorated),
            'findings' => array_values($decorated),
        ];
    }

    /**
     * Tally findings by severity. Always returns every severity key, even
     * those with a zero count, so downstream consumers can rely on the shape.
     * v1 adds a {@see self::SUMMARY_DEPRECATED_KEY} entry counting findings
     * carrying the optional `deprecated_in` field.
     *
     * @param list<array<string,mixed>> $findings
     *
     * @return array<string,int>
     */
    private function summarise(array $findings): array
    {
        $summary = [];
        foreach (Severity::ALL as $severity) {
            $summary[$severity] = 0;
        }
        $summary[self::SUMMARY_DEPRECATED_KEY] = 0;

        foreach ($findings as $finding) {
            $severity = $finding['severity'] ?? null;
            if (is_string($severity) && Severity::isValid($severity)) {
                $summary[$severity]++;
            }
            if (isset($finding['deprecated_in'])) {
                $summary[self::SUMMARY_DEPRECATED_KEY]++;
            }
        }

        return $summary;
    }

    /**
     * Decorate findings whose id is registered as deprecated with the
     * additive v1 fields. Findings that already carry a `deprecated_in`
     * key (e.g. injected by a future check class) are left untouched so
     * a check can over-ride the central metadata when warranted.
     *
     * @param list<array<string,mixed>> $findings
     *
     * @return list<array<string,mixed>>
     */
    private function decorate(array $findings): array
    {
        if ($this->deprecations === null) {
            return array_values($findings);
        }
        $out = [];
        foreach ($findings as $finding) {
            $id = $finding['id'] ?? null;
            if (
                is_string($id)
                && !isset($finding['deprecated_in'])
                && $this->deprecations->isDeprecated($id)
            ) {
                $meta = $this->deprecations->metadataFor($id);
                if ($meta !== null) {
                    $finding['deprecated_in'] = $meta['deprecated_in'];
                    $finding['removal_in'] = $meta['removal_in'];
                    $finding['replacement'] = $meta['replacement'];
                    $finding['migration_url'] = $meta['migration_url'];
                }
            }
            $out[] = $finding;
        }
        return $out;
    }
}
