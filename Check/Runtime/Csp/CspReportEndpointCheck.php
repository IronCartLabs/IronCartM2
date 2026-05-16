<?php

/**
 * IronCart_Scan — IC-081 CSP report endpoint not configured.
 *
 * Without `report-uri` (legacy) or `report-to` (RFC 8942), a CSP that
 * blocks a skimmer attempt is invisible to the operator. The check
 * fires only when the storefront returned at least one CSP header
 * (either enforced or report-only) — there's no point flagging the
 * report endpoint as missing when IC-080 already says CSP itself is
 * missing.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Runtime\Csp;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Report\Severity;

/**
 * IC-081 — at least one of `report-uri` / `report-to` should be set.
 */
class CspReportEndpointCheck implements CheckInterface
{
    public const ID = 'IC-081';

    private const TITLE = 'CSP has no report-uri or report-to directive';
    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-081';

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
            // IC-080 handles "no CSP at all"; bail to avoid double-counting.
            return [];
        }

        $directives = CspHeaderParser::parse($rawCsp);
        if (isset($directives['report-uri']) || isset($directives['report-to'])) {
            return [];
        }

        return [[
            'id' => self::ID,
            'title' => self::TITLE,
            'severity' => Severity::MEDIUM,
            'evidence' => [
                'probed_url' => $result->probedUrl,
                'csp_header_source' => $result->cspHeader !== null
                    ? 'content-security-policy'
                    : 'content-security-policy-report-only',
                'report_uri' => null,
                'report_to' => null,
            ],
            'remediation_url' => self::REMEDIATION_URL,
        ]];
    }
}
