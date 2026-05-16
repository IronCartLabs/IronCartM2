<?php

/**
 * IronCart_Scan — IC-080 storefront CSP missing entirely.
 *
 * HEAD-probes the storefront base URL once (via {@see CspProbeRunner}
 * which the rest of the IC-08x pack shares) and emits a HIGH finding
 * if neither `Content-Security-Policy` nor
 * `Content-Security-Policy-Report-Only` is returned. The report-only
 * fallback prevents this check from double-counting with IC-084 (which
 * flags report-only in production explicitly).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Runtime\Csp;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Report\Severity;

/**
 * IC-080 — storefront should ship a `Content-Security-Policy` header.
 */
class CspMissingCheck implements CheckInterface
{
    public const ID = 'IC-080';

    private const TITLE = 'Storefront response has no Content-Security-Policy header';
    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-080';

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
            // Skip — IC-085 / the probe-failure handler in the runner
            // owns those code paths. We don't want to double-report.
            return [];
        }

        if ($result->cspHeader !== null || $result->cspReportOnlyHeader !== null) {
            return [];
        }

        return [[
            'id' => self::ID,
            'title' => self::TITLE,
            'severity' => Severity::HIGH,
            'evidence' => [
                'probed_url' => $result->probedUrl,
                'content_security_policy' => null,
                'content_security_policy_report_only' => null,
            ],
            'remediation_url' => self::REMEDIATION_URL,
        ]];
    }
}
