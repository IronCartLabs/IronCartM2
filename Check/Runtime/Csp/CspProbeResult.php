<?php

/**
 * IronCart_Scan — outcome of one CSP storefront probe.
 *
 * Shared by every IC-08x check so a single HTTP request services the
 * whole pack ({@see CspProbeRunner}). The value object carries:
 *
 *   - the URL that was probed (for evidence rendering),
 *   - the raw `Content-Security-Policy` header value if present,
 *   - the raw `Content-Security-Policy-Report-Only` header value if
 *     present,
 *   - a "skipped" flag distinguishing "we deliberately didn't probe"
 *     (loopback guard rejected, base URL unconfigured) from "the probe
 *     fired but the server returned no CSP".
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Runtime\Csp;

/**
 * Immutable result of one CSP probe. Constructed only by
 * {@see CspProbeRunner}.
 */
final class CspProbeResult
{
    public function __construct(
        public readonly string $probedUrl,
        public readonly bool $probeAttempted,
        public readonly ?string $cspHeader,
        public readonly ?string $cspReportOnlyHeader,
        public readonly ?string $skipReason = null
    ) {
    }

    public static function skipped(string $url, string $reason): self
    {
        return new self(
            probedUrl: $url,
            probeAttempted: false,
            cspHeader: null,
            cspReportOnlyHeader: null,
            skipReason: $reason
        );
    }

    public static function probed(
        string $url,
        ?string $cspHeader,
        ?string $cspReportOnlyHeader
    ): self {
        return new self(
            probedUrl: $url,
            probeAttempted: true,
            cspHeader: $cspHeader,
            cspReportOnlyHeader: $cspReportOnlyHeader,
            skipReason: null
        );
    }
}
