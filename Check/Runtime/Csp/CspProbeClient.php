<?php

/**
 * IronCart_Scan — HTTP probe abstraction for the CSP check pack.
 *
 * The check classes never depend on cURL directly. They depend on this
 * interface so the production cURL implementation can be swapped for a
 * fake in unit tests (and so a future v3+ remote-call client can
 * coexist without rewriting the checks).
 *
 * Implementations MUST:
 *
 *   - issue a HEAD request (not GET) — we don't want to trigger
 *     analytics, edge-cache writes, or any storefront-side action,
 *   - apply a 5-second total timeout (connect + read), to keep the
 *     scan fast on flaky local networks,
 *   - follow ZERO redirects — chasing a 301/302 to an off-host
 *     destination would defeat the loopback guard,
 *   - set the `User-Agent: IronCart-Scan/<module-version> (security-posture-check)`
 *     header so server-side logs can attribute the request,
 *   - return only the response headers — never the body.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Runtime\Csp;

/**
 * Contract for one storefront HEAD probe.
 *
 * @phpstan-type CspProbeHeaders array<string, string>
 */
interface CspProbeClient
{
    /**
     * Probe `$url` and return its response headers, or null if the
     * request failed (timeout, DNS, refused connection, non-HTTP error).
     *
     * Header keys MUST be lowercased so the caller can look them up
     * without case-coercion. Multiple header values for the same name
     * are joined with `, ` per RFC 7230 §3.2.2.
     *
     * @param string $url        Absolute URL to HEAD. Must already have
     *                           passed the {@see LoopbackHostGuard}.
     * @param string $userAgent  UA string to send on the request.
     *
     * @return CspProbeHeaders|null  Lowercased header map, or null on
     *                               transport failure.
     */
    public function head(string $url, string $userAgent): ?array;
}
