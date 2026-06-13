<?php

/**
 * IronCart_Scan — hardened cURL CVE proxy client.
 *
 * Production implementation of {@see CveProxyClient}. Uses ext-curl
 * directly rather than `Magento\Framework\HTTP\Client\Curl` so we can
 * pin `CURLOPT_PROTOCOLS`, `CURLOPT_FOLLOWLOCATION=false`, and
 * `CURLOPT_MAXREDIRS=0` — the same hardening pattern the IC-08x CSP
 * probe uses, sized for the longer round-trip of the proxy fan-out.
 *
 * The host-check is performed in pure PHP *before* `curl_exec` runs, so
 * a misconfigured destination URL (e.g. `https://evil.com/api/cve` from
 * a malicious admin config edit) never reaches DNS resolution. This is
 * the egress allowlist that the IC-060 issue body locks in.
 *
 * The SSRF guards (FOLLOWLOCATION=false, MAXREDIRS=0, cookie strip,
 * RETURNTRANSFER pin) are owned by
 * {@see \IronCart\Scan\Check\Http\HardenedCurlClientTrait}. This class
 * provides the per-call options that legitimately differ between the
 * three hardened clients (POST body, JSON headers, TLS-verify on,
 * 10s/30s timeouts, 5 MiB body cap).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Cve;

use CurlHandle;
use IronCart\Scan\Check\Http\HardenedCurlClientTrait;
use JsonException;

/**
 * Production CVE proxy client backed by ext-curl.
 */
class CurlCveProxyClient implements CveProxyClient
{
    use HardenedCurlClientTrait;

    /**
     * Connect timeout — fail fast if the proxy is unreachable.
     */
    private const CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * Total request timeout (connect + read). The proxy fan-outs to
     * OSV.dev; cold-cache responses on a full Magento package list have
     * been measured at ~10-15s, so 30s leaves headroom without blocking
     * the scan indefinitely.
     */
    private const TIMEOUT_SECONDS = 30;

    /**
     * Maximum response body size we will buffer (in bytes). The proxy
     * returns at most one finding per requested package; even a worst-
     * case Magento install with 500 packages × ~1KB per finding fits
     * comfortably here. Anything larger is almost certainly a misconfig
     * or attack, so we abort the body read.
     */
    private const MAX_RESPONSE_BYTES = 5 * 1024 * 1024; // 5 MiB

    /**
     * @inheritDoc
     */
    public function post(string $url, array $payload, string $userAgent): ?array
    {
        if (!self::hostMatches($url, self::ALLOWED_HOST)) {
            // Hard reject — never reach DNS for a non-ironcart.dev host.
            return null;
        }

        if (!function_exists('curl_init')) {
            return null;
        }

        try {
            $body = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            return null;
        }

        $ch = curl_init();
        if (!$ch instanceof CurlHandle) {
            return null;
        }

        $bytesRead = 0;
        $bodyBuf = '';
        $writeCallback = static function (CurlHandle $_handle, string $chunk) use (&$bytesRead, &$bodyBuf): int {
            $bytesRead += strlen($chunk);
            if ($bytesRead > self::MAX_RESPONSE_BYTES) {
                // Returning a value other than strlen($chunk) aborts the
                // transfer with CURLE_WRITE_ERROR. Belt-and-braces against
                // a runaway response body.
                return 0;
            }
            $bodyBuf .= $chunk;
            return strlen($chunk);
        };

        $this->applyHardenedOptions($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                sprintf('Content-Length: %d', strlen($body)),
            ],
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            // Constrain the protocol set so a redirect-injected
            // `gopher://` / `file://` / `dict://` URL on a vulnerable
            // libcurl can't escape. Belt-and-braces alongside
            // FOLLOWLOCATION=false (owned by the trait).
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_WRITEFUNCTION => $writeCallback,
            // Public ironcart.dev — full TLS validation, no relaxation.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // No curl_close(): deprecated in PHP 8.5 and a no-op since PHP
        // 8.0 — $ch is a CurlHandle object, freed when it leaves scope.
        // Under Magento's error handler the E_DEPRECATED becomes a
        // thrown exception, aborting the scan (caught by the 2.4.9 ×
        // PHP 8.5 CI cell, #196).

        if ($ok === false || $errno !== 0) {
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return null;
        }

        try {
            $decoded = json_decode($bodyBuf, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
