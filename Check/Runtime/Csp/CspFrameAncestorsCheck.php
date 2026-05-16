<?php

/**
 * IronCart_Scan — IC-083 `frame-ancestors` missing or wildcard.
 *
 * `frame-ancestors` is the modern, CSP-defined replacement for
 * `X-Frame-Options`. Magento's checkout and account pages are still
 * common UI-redress targets (overlaying the live checkout iframe with
 * a transparent click-jack). Either an absent `frame-ancestors`
 * directive or one set to `*` leaves the storefront frame-embeddable
 * by anyone.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Runtime\Csp;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Report\Severity;

/**
 * IC-083 — `frame-ancestors` must be set and must not be `*`.
 */
class CspFrameAncestorsCheck implements CheckInterface
{
    public const ID = 'IC-083';

    private const TITLE_MISSING = 'Storefront CSP has no frame-ancestors directive';
    private const TITLE_WILDCARD = 'Storefront CSP frame-ancestors is set to *';

    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-083';

    public function __construct(
        private readonly CspProbeRunner $probeRunner
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(): array
    {
        $result = $this->probeRunner->probe();
        if (!$result->probeAttempted) {
            return [];
        }

        $rawCsp = $result->cspHeader ?? $result->cspReportOnlyHeader;
        if ($rawCsp === null) {
            return [];
        }

        $directives = CspHeaderParser::parse($rawCsp);

        if (!isset($directives['frame-ancestors'])) {
            return [[
                'id' => self::ID,
                'title' => self::TITLE_MISSING,
                'severity' => Severity::MEDIUM,
                'evidence' => [
                    'probed_url' => $result->probedUrl,
                    'csp_header_source' => $result->cspHeader !== null
                        ? 'content-security-policy'
                        : 'content-security-policy-report-only',
                    'frame_ancestors' => null,
                ],
                'remediation_url' => self::REMEDIATION_URL,
            ]];
        }

        if (in_array('*', $directives['frame-ancestors'], true)) {
            return [[
                'id' => self::ID,
                'title' => self::TITLE_WILDCARD,
                'severity' => Severity::MEDIUM,
                'evidence' => [
                    'probed_url' => $result->probedUrl,
                    'csp_header_source' => $result->cspHeader !== null
                        ? 'content-security-policy'
                        : 'content-security-policy-report-only',
                    'frame_ancestors' => $directives['frame-ancestors'],
                ],
                'remediation_url' => self::REMEDIATION_URL,
            ]];
        }

        return [];
    }
}
