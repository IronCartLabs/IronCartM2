<?php

/**
 * IronCart_Scan — HTTP client abstraction for the IC-060 CVE proxy check.
 *
 * Defines the single sanctioned outbound surface the IC-060 / IC-061 pack
 * uses to call the ironcart.dev CVE proxy. The check class never depends on
 * cURL directly — it depends on this interface so production wires the
 * {@see CurlCveProxyClient} and unit tests inject a deterministic fake.
 *
 * Implementations MUST:
 *
 *   - assert the target URL host equals `ironcart.dev` *before* opening a
 *     socket — anything else MUST be rejected without contacting DNS,
 *   - follow ZERO redirects (a 301/302 to a different host would defeat
 *     the host check),
 *   - constrain the protocol set to HTTP / HTTPS only,
 *   - apply a 10-second connect timeout and a 30-second total timeout,
 *   - send no cookies and a stable
 *     `User-Agent: IronCart-Scan/<module-version> (cve-cross-reference)` header,
 *   - return the decoded JSON response body on a 2xx, or null on any
 *     transport / non-2xx / parse failure.
 *
 * The 10s/30s budget is intentionally looser than the IC-08x CSP probe
 * (5s) because the proxy makes a fan-out request to api.osv.dev and the
 * cold-cache path can legitimately take ~10-15s for a full Magento
 * package list.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Cve;

/**
 * Contract for one POST to the ironcart.dev CVE proxy.
 *
 * @phpstan-type CveProxyResponse array<string, mixed>
 */
interface CveProxyClient
{
    /**
     * POST `$payload` to `$url` and return the decoded JSON response.
     *
     * Implementations MUST validate that `parse_url($url, PHP_URL_HOST)`
     * matches {@see self::ALLOWED_HOST} (case-insensitive) before issuing
     * the request. If the host check fails the implementation MUST
     * return null and MUST NOT contact DNS or open a socket.
     *
     * @param string               $url        Absolute URL — only the
     *                                         ironcart.dev host is
     *                                         accepted.
     * @param array<string, mixed> $payload    Already-shaped POST body;
     *                                         the implementation
     *                                         json-encodes it.
     * @param string               $userAgent  UA string to send.
     *
     * @return CveProxyResponse|null  Decoded JSON body on 2xx success;
     *                                null on host-check failure,
     *                                transport error, non-2xx response,
     *                                or JSON parse failure.
     */
    public function post(string $url, array $payload, string $userAgent): ?array;

    /**
     * The only outbound destination this client may contact.
     *
     * Exposed as a public constant so {@see ComposerCveCheck} can also
     * sanity-check the configured URL at construction time without
     * reaching into the implementation.
     */
    public const ALLOWED_HOST = 'ironcart.dev';
}
