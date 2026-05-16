<?php

/**
 * IronCart_Scan — IC-082 `script-src` uses `unsafe-inline` / `unsafe-eval`.
 *
 * `unsafe-inline` defeats CSP's whole purpose against Magecart-style
 * inline-script skimmers; `unsafe-eval` is rarer but is still a
 * Magecart-friendly escape hatch. Either keyword anywhere in
 * `script-src` (enforced or report-only) trips the check.
 *
 * When `script-src` isn't set at all we fall back to `default-src`,
 * which is what the user agent uses for script context per CSP3 §6.1.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Runtime\Csp;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Report\Severity;

/**
 * IC-082 — `script-src` (or `default-src` fallback) must not include
 * `'unsafe-inline'` or `'unsafe-eval'`.
 */
class CspScriptSrcUnsafeCheck implements CheckInterface
{
    public const ID = 'IC-082';

    private const TITLE = "Storefront CSP script-src allows 'unsafe-inline' or 'unsafe-eval'";
    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-082';

    private const UNSAFE_KEYWORDS = ["'unsafe-inline'", "'unsafe-eval'"];

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
        $scriptSrc = $directives['script-src']
            ?? $directives['default-src']
            ?? null;
        if ($scriptSrc === null) {
            return [];
        }

        $tokensLower = array_map('strtolower', $scriptSrc);
        $offending = array_values(array_intersect($tokensLower, self::UNSAFE_KEYWORDS));
        if ($offending === []) {
            return [];
        }

        return [[
            'id' => self::ID,
            'title' => self::TITLE,
            'severity' => Severity::HIGH,
            'evidence' => [
                'probed_url' => $result->probedUrl,
                'csp_header_source' => $result->cspHeader !== null
                    ? 'content-security-policy'
                    : 'content-security-policy-report-only',
                'directive' => isset($directives['script-src']) ? 'script-src' : 'default-src',
                'offending_tokens' => $offending,
            ],
            'remediation_url' => self::REMEDIATION_URL,
        ]];
    }
}
