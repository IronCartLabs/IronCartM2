<?php

/**
 * IronCart_Scan — hardened cURL upload client.
 *
 * Production implementation of {@see UploadClient}. Models the same
 * defense-in-depth pattern the IC-080..IC-085 CSP probe uses
 * ({@see \IronCart\Scan\Check\Runtime\Csp\CurlCspProbeClient}) but sized
 * for the longer round-trip of an upload payload:
 *
 *   - Host pin enforced in pure PHP before `curl_exec` runs — a misconfigured
 *     endpoint URL never reaches DNS resolution.
 *   - `FOLLOWLOCATION = false`, `MAXREDIRS = 0`. A 30x to a different host
 *     cannot defeat the host pin.
 *   - `PROTOCOLS = REDIR_PROTOCOLS = HTTPS only`. No HTTP, FTP, file, gopher.
 *   - Full TLS validation (`SSL_VERIFYPEER = true`, `SSL_VERIFYHOST = 2`).
 *     ironcart.dev is public; we do not relax verification.
 *   - Response body bounded by `MAX_RESPONSE_BYTES` via `WRITEFUNCTION`.
 *     A runaway response can never blow out the scanner process.
 *   - Response body is parsed for `view_url` ONLY. Other server-side fields
 *     are deliberately discarded — the client never echoes a server message
 *     verbatim, so we can't accidentally render a stack trace from a misconfigured
 *     IronCartWeb instance into the operator's terminal.
 *   - One retry on 5xx / transport timeout, then fail. No retry on 4xx — those
 *     are configuration errors and retrying just spams.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Upload;

use CurlHandle;
use JsonException;

/**
 * Production upload client backed by ext-curl.
 */
class CurlUploadClient implements UploadClient
{
    /**
     * Connect timeout — fail fast if the endpoint is unreachable.
     */
    private const CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * Total request timeout (connect + read). Uploads can be slower than the
     * CVE-proxy round-trip because the payload includes the full findings
     * list plus composer package list — 60s leaves headroom for a worst-case
     * Magento install on a slow link.
     */
    private const TIMEOUT_SECONDS = 60;

    /**
     * Maximum response body size we will buffer (in bytes). The server
     * response is a small JSON envelope (`view_url`, `report_id`, etc.);
     * 256 KiB is two orders of magnitude beyond what we'd expect, so
     * anything past it is treated as a runaway response and aborted.
     */
    private const MAX_RESPONSE_BYTES = 256 * 1024;

    /**
     * Backoff in microseconds before the single retry on 5xx / transport
     * timeout. Two seconds — long enough to clear most transient
     * server-side blips without significantly extending the scan time on
     * a hard failure.
     */
    private const RETRY_BACKOFF_USECONDS = 2_000_000;

    /**
     * @inheritDoc
     */
    public function post(
        string $url,
        array $payload,
        string $bearerToken,
        string $userAgent,
        string $allowedHost
    ): UploadClientResult {
        if (!$this->hostIsAllowed($url, $allowedHost)) {
            // Hard reject — never reach DNS for a non-allowlisted host.
            return new UploadClientResult(null, null, UploadClientResult::CATEGORY_HOST_REJECTED);
        }

        if (!function_exists('curl_init')) {
            return new UploadClientResult(null, null, UploadClientResult::CATEGORY_TRANSPORT);
        }

        try {
            $body = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            return new UploadClientResult(null, null, UploadClientResult::CATEGORY_TRANSPORT);
        }

        // Single retry on 5xx / transport timeout. No retry on 4xx.
        $result = $this->postOnce($url, $body, $bearerToken, $userAgent);
        if ($this->shouldRetry($result)) {
            usleep(self::RETRY_BACKOFF_USECONDS);
            $result = $this->postOnce($url, $body, $bearerToken, $userAgent);
        }

        return $result;
    }

    /**
     * Issue exactly one HTTPS POST and return a categorised result.
     */
    private function postOnce(
        string $url,
        string $body,
        string $bearerToken,
        string $userAgent
    ): UploadClientResult {
        $ch = curl_init();
        if (!$ch instanceof CurlHandle) {
            return new UploadClientResult(null, null, UploadClientResult::CATEGORY_TRANSPORT);
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

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $bearerToken,
                'Content-Type: application/json',
                'Accept: application/json',
                sprintf('Content-Length: %d', strlen($body)),
            ],
            // SSRF guard — never chase redirects. A 30x to a different host
            // would defeat the ironcart.dev host check above.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            // HTTPS only on both initial and (would-be) redirect transfers.
            // Belt-and-braces alongside FOLLOWLOCATION=false in case a
            // future libcurl version honours redirects despite the flag.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => $userAgent,
            // Don't send cookies. The upload is anonymous (server-to-server)
            // and must not pick up any session state from the caller's
            // environment.
            CURLOPT_COOKIE => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_WRITEFUNCTION => $writeCallback,
            // Public ironcart.dev — full TLS validation, no relaxation.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($ok === false || $errno !== 0) {
            $category = ($errno === CURLE_OPERATION_TIMEDOUT)
                ? UploadClientResult::CATEGORY_TIMEOUT
                : UploadClientResult::CATEGORY_TRANSPORT;
            return new UploadClientResult(null, null, $category);
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $viewUrl = $this->extractViewUrl($bodyBuf);
            return new UploadClientResult($httpCode, $viewUrl, UploadClientResult::CATEGORY_OK);
        }

        return new UploadClientResult($httpCode, null, $this->categoriseFailure($httpCode));
    }

    /**
     * Was this result eligible for the one allowed retry?
     */
    private function shouldRetry(UploadClientResult $result): bool
    {
        if ($result->category === UploadClientResult::CATEGORY_TIMEOUT) {
            return true;
        }
        if ($result->category === UploadClientResult::CATEGORY_TRANSPORT && $result->httpCode === null) {
            return true;
        }
        if ($result->httpCode !== null && $result->httpCode >= 500 && $result->httpCode < 600) {
            return true;
        }
        return false;
    }

    /**
     * Map an HTTP status code to a stable category. Avoids leaking the
     * exact code to stderr when we just want "auth", "too large", etc.
     */
    private function categoriseFailure(int $httpCode): string
    {
        return match (true) {
            $httpCode === 401 || $httpCode === 403 => UploadClientResult::CATEGORY_AUTH,
            $httpCode === 413                       => UploadClientResult::CATEGORY_PAYLOAD_TOO_LARGE,
            $httpCode === 400 || $httpCode === 422 => UploadClientResult::CATEGORY_BAD_REQUEST,
            $httpCode >= 500 && $httpCode < 600    => UploadClientResult::CATEGORY_SERVER,
            default                                 => UploadClientResult::CATEGORY_OTHER,
        };
    }

    /**
     * Pull only the `view_url` key out of a 2xx response body. Returning
     * anything else (e.g. server-side warnings, debug fields) would risk
     * accidentally surfacing internal IronCartWeb state to the operator's
     * terminal — `view_url` is the one field we render verbatim.
     */
    private function extractViewUrl(string $body): ?string
    {
        try {
            $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        $viewUrl = $decoded['view_url'] ?? null;
        return is_string($viewUrl) && $viewUrl !== '' ? $viewUrl : null;
    }

    /**
     * Return true iff the URL's host equals the allowed host (case-
     * insensitive). Anything else — including subdomains like
     * `evil.ironcart.dev.attacker.com` — is rejected.
     *
     * `parse_url` returns the raw host without the port, exactly what we
     * want for an exact-string allowlist comparison.
     */
    private function hostIsAllowed(string $url, string $allowedHost): bool
    {
        $host = parse_url(trim($url), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        return strcasecmp($host, $allowedHost) === 0;
    }
}
