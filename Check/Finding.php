<?php

/**
 * IronCart_Scan — finding helper.
 *
 * Tiny value-object-ish builder so individual check classes don't have to
 * hand-assemble the canonical finding array shape every time. The shape
 * itself is owned by {@see \IronCart\Scan\Report\ReportBuilder}.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check;

use IronCart\Scan\Report\Severity;
use InvalidArgumentException;

/**
 * Helper for constructing the canonical finding array shape.
 */
final class Finding
{
    /**
     * Build a finding array.
     *
     * @param string $id              Stable identifier — typically the check id, optionally suffixed
     * @param string $title           Short human-readable headline
     * @param string $severity        One of {@see Severity::ALL}
     * @param mixed  $evidence        Structured evidence (array preferred); rendered as JSON in reports
     * @param string $remediationUrl  Link to remediation docs (may be empty in v0)
     *
     * @return array{id:string,title:string,severity:string,evidence:mixed,remediation_url:string}
     */
    public static function make(
        string $id,
        string $title,
        string $severity,
        mixed $evidence = null,
        string $remediationUrl = ''
    ): array {
        if (!Severity::isValid($severity)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown severity "%s". Valid: %s',
                $severity,
                implode(', ', Severity::ALL)
            ));
        }

        return [
            'id' => $id,
            'title' => $title,
            'severity' => $severity,
            'evidence' => $evidence,
            'remediation_url' => $remediationUrl,
        ];
    }

    private function __construct()
    {
    }
}
