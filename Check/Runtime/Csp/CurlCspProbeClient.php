<?php

/**
 * IronCart_Scan — cURL-backed CSP probe client.
 *
 * Production implementation of {@see CspProbeClient}. Uses ext-curl
 * (a hard dependency of Magento 2 since 2.3) directly rather than
 * `Magento\Framework\HTTP\Client\Curl` because the framework wrapper
 * does not expose `CURLOPT_PROTOCOLS` / `CURLOPT_FOLLOWLOCATION` /
 * `CURLOPT_MAXREDIRS`, and those flags are the difference between a
 * safe local probe and a confused-deputy that can be coerced into
 * SSRF.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Runtime\Csp;

use CurlHandle;

/**
 * Production CSP probe client backed by ext-curl.
 */
class CurlCspProbeClient implements CspProbeClient
{
    /**
     * Total request timeout (connect + read) in seconds. The check pack
     * is run inline inside `bin/magento ironcart:scan`, so we keep this
     * tight — a hung probe blocks the whole scan.
     */
    private const TIMEOUT_SECONDS = 5;

    /**
     * @inheritDoc
     */
    public function head(string $url, string $userAgent): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init();
        if (!$ch instanceof CurlHandle) {
            return null;
        }

        $headers = [];
        $collect = static function (CurlHandle $_handle, string $line) use (&$headers): int {
            $length = strlen($line);
            // cURL invokes the header function once per header line
            // (including the HTTP status line and the trailing CRLF
            // separator). Skip non-`name: value` lines.
            $trim = rtrim($line, "\r\n");
            if ($trim === '' || str_starts_with($trim, 'HTTP/')) {
                return $length;
            }

            $sep = strpos($trim, ':');
            if ($sep === false) {
                return $length;
            }

            $name = strtolower(trim(substr($trim, 0, $sep)));
            $value = trim(substr($trim, $sep + 1));
            if ($name === '') {
                return $length;
            }

            // RFC 7230 §3.2.2: combine same-name headers with `, `.
            if (isset($headers[$name])) {
                $headers[$name] .= ', ' . $value;
            } else {
                $headers[$name] = $value;
            }

            return $length;
        };

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true,                   // HEAD
            CURLOPT_CUSTOMREQUEST => 'HEAD',
            CURLOPT_FOLLOWLOCATION => false,           // SSRF guard — never chase redirects
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            // Constrain the protocol set so a redirect-injected
            // `gopher://` / `file://` / `dict://` URL on a vulnerable
            // libcurl can't escape. Belt-and-braces alongside
            // FOLLOWLOCATION=false.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => $userAgent,
            // Don't send cookies. The probe is anonymous and must not
            // pick up any session state from the caller's environment.
            CURLOPT_COOKIE => '',
            CURLOPT_HEADERFUNCTION => $collect,
            // Local-loopback self-signed certs are common in dev — we
            // accept them because LoopbackHostGuard has already
            // restricted the destination to loopback / RFC1918 /
            // configured-base-URL.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($ok === false || $errno !== 0) {
            return null;
        }

        return $headers;
    }
}
