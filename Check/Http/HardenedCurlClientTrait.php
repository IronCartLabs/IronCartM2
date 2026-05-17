<?php

/**
 * IronCart_Scan — shared SSRF-hardening trait for outbound cURL clients.
 *
 * Three production HTTP clients in this module were carrying the same
 * `curl_setopt_array` boilerplate verbatim (FOLLOWLOCATION=false,
 * MAXREDIRS=0, PROTOCOLS pinned, cookies stripped, host check before
 * socket open):
 *
 *   - {@see \IronCart\Scan\Check\Cve\CurlCveProxyClient}   (IC-060 OSV proxy POST)
 *   - {@see \IronCart\Scan\Check\Runtime\Csp\CurlCspProbeClient} (IC-08x CSP HEAD)
 *   - {@see \IronCart\Scan\Check\Upload\CurlUploadClient}  (`--upload` POST)
 *
 * CLAUDE.md mandates lifting a pattern once it repeats 3+ times. This
 * trait owns the SSRF guards once so a future libcurl-CVE regression or
 * policy bump only needs to touch one file. Concrete clients still own
 * everything that legitimately differs between them (TLS verify posture,
 * timeouts, body callback, retry policy, response shape).
 *
 * ## Hardening invariants enforced by `applyHardenedOptions()`
 *
 * The trait sets the following cURL options *after* merging caller-
 * supplied overrides, so a typo in a subclass cannot accidentally
 * un-set a guard:
 *
 *   - `CURLOPT_FOLLOWLOCATION => false`  — no redirect chase
 *   - `CURLOPT_MAXREDIRS      => 0`      — belt-and-braces with above
 *   - `CURLOPT_COOKIE         => ''`     — strip any ambient session cookies
 *   - `CURLOPT_RETURNTRANSFER => true`   — never echo body to stdout
 *
 * The caller MUST supply, via `$overrides`, the four options that vary
 * legitimately between clients (validated by `assertRequiredOverrides`):
 *
 *   - `CURLOPT_PROTOCOLS`       — pinned protocol set for the initial transfer
 *   - `CURLOPT_REDIR_PROTOCOLS` — pinned protocol set for any (would-be) redirect
 *   - `CURLOPT_CONNECTTIMEOUT`  — connect timeout in seconds
 *   - `CURLOPT_TIMEOUT`         — total timeout (connect + read) in seconds
 *
 * Leaving any of these implicit would be a security regression (e.g. a
 * subclass that forgot `PROTOCOLS` would inherit libcurl's full protocol
 * list including `file://`, `gopher://`, `dict://`). The assertion fires
 * a `LogicException` rather than silently filling in a default so the
 * mistake surfaces in unit tests rather than in production.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Http;

use CurlHandle;
use LogicException;

/**
 * Shared SSRF hardening for ext-curl outbound clients.
 *
 * Intended to be `use`d by concrete `Curl*Client` classes. Not an
 * abstract base because the three call sites have different `post()` /
 * `head()` signatures (POST with JSON body, POST with JSON body + bearer
 * token, HEAD with no body) and forcing a common method shape would
 * obscure the differences that matter for review.
 */
trait HardenedCurlClientTrait
{
    /**
     * Apply the shared SSRF guards plus the caller's per-request options.
     *
     * Order matters:
     *
     *   1. Set the caller's `$overrides` first (URL, POST, headers,
     *      TLS posture, body callback, etc.).
     *   2. Re-apply the four invariant guards AFTER the overrides so a
     *      subclass that mistakenly passes `CURLOPT_FOLLOWLOCATION => true`
     *      in its overrides array cannot defeat the redirect guard.
     *
     * @param CurlHandle           $ch        Initialised cURL handle.
     * @param array<int, mixed>    $overrides Per-request cURL options. MUST include
     *                                        `CURLOPT_PROTOCOLS`, `CURLOPT_REDIR_PROTOCOLS`,
     *                                        `CURLOPT_CONNECTTIMEOUT`, `CURLOPT_TIMEOUT`.
     *
     * @throws LogicException if any required override is missing.
     */
    protected function applyHardenedOptions(CurlHandle $ch, array $overrides): void
    {
        $this->assertRequiredOverrides($overrides);

        // Step 1: apply the caller's per-request options first. These
        // include the URL, method, body, headers, TLS-verify posture,
        // user agent, timeouts, protocol pins, and write/header callbacks.
        curl_setopt_array($ch, $overrides);

        // Step 2: re-apply the SSRF invariants AFTER the overrides. If a
        // subclass typoed `CURLOPT_FOLLOWLOCATION => true` into its
        // overrides array, this step un-sets it. The reviewer-visible
        // guarantee: these four options always carry these exact values
        // when `applyHardenedOptions` returns.
        curl_setopt_array($ch, [
            // SSRF guard — never chase redirects. A 30x to a different
            // host would defeat the per-client host check.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            // Strip cookies. Outbound scanner traffic is anonymous and
            // must not pick up any session state from the caller's
            // environment.
            CURLOPT_COOKIE         => '',
            // Never echo the response body to stdout — every concrete
            // client either buffers via WRITEFUNCTION or discards.
            CURLOPT_RETURNTRANSFER => true,
        ]);
    }

    /**
     * Exact-string host allow-list check.
     *
     * Returns true iff the URL's host equals `$allowedHost`
     * (case-insensitive). Anything else — including suffix-match attacks
     * like `evil.ironcart.dev.attacker.com` — is rejected.
     *
     * `parse_url` returns the raw host without the port, exactly what we
     * want for an exact-string allow-list comparison. The check runs in
     * pure PHP *before* a cURL handle is opened so a misconfigured URL
     * never reaches DNS resolution.
     *
     * Used by the host-pinned clients (CVE proxy, upload). The CSP probe
     * uses {@see \IronCart\Scan\Check\Runtime\Csp\LoopbackHostGuard}
     * instead because its allow-list is shaped (loopback / RFC1918 /
     * configured base URL), not a single string.
     */
    protected static function hostMatches(string $url, string $allowedHost): bool
    {
        $host = parse_url(trim($url), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        return strcasecmp($host, $allowedHost) === 0;
    }

    /**
     * Fail-fast assertion that the caller has supplied every option that
     * legitimately varies between clients.
     *
     * Leaving any of these implicit would be a security regression:
     *
     *   - missing `CURLOPT_PROTOCOLS` / `CURLOPT_REDIR_PROTOCOLS` →
     *     libcurl falls back to its full protocol set including
     *     `file://`, `gopher://`, `dict://`,
     *   - missing `CURLOPT_TIMEOUT` / `CURLOPT_CONNECTTIMEOUT` →
     *     libcurl will block indefinitely on a stalled connection,
     *     hanging the scan.
     *
     * @param array<int, mixed> $overrides
     *
     * @throws LogicException if any required option is missing.
     */
    private function assertRequiredOverrides(array $overrides): void
    {
        $required = [
            CURLOPT_PROTOCOLS       => 'CURLOPT_PROTOCOLS',
            CURLOPT_REDIR_PROTOCOLS => 'CURLOPT_REDIR_PROTOCOLS',
            CURLOPT_CONNECTTIMEOUT  => 'CURLOPT_CONNECTTIMEOUT',
            CURLOPT_TIMEOUT         => 'CURLOPT_TIMEOUT',
        ];
        foreach ($required as $opt => $name) {
            if (!array_key_exists($opt, $overrides)) {
                throw new LogicException(sprintf(
                    'HardenedCurlClientTrait::applyHardenedOptions requires %s '
                    . 'in $overrides — concrete client must pin the value '
                    . 'explicitly to avoid inheriting an unsafe libcurl default.',
                    $name
                ));
            }
        }
    }
}
