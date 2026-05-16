<?php

/**
 * IronCart_Scan — upload client contract.
 *
 * Abstraction over the single outbound POST `bin/magento ironcart:scan --upload`
 * makes against the IronCartWeb ingest endpoint. The interface exists so the
 * unit-test suite can swap in a fake without driving real cURL — the production
 * implementation lives in {@see CurlUploadClient} and pins the destination
 * host to `ironcart.dev`.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Upload;

/**
 * Contract for the upload HTTP client. Implementations MUST:
 *
 * - reject any URL whose host is not `ironcart.dev` (or the staging override
 *   the operator pasted in admin config — see {@see UploadConfig});
 * - disable HTTP redirects entirely (FOLLOWLOCATION=0, MAXREDIRS=0);
 * - perform full TLS validation against the public CA store;
 * - never write the response body to stderr / stdout — only the `view_url`
 *   from a 2xx body, and a stable category label otherwise.
 */
interface UploadClient
{
    /**
     * The host that production uploads pin to. Constant-exposed so the
     * unit tests can assert the allow-list without reaching for a fixture
     * file.
     */
    public const ALLOWED_HOST = 'ironcart.dev';

    /**
     * POST the JSON payload to the configured endpoint.
     *
     * @param string               $url         Full HTTPS URL.
     * @param array<string,mixed>  $payload     Already validated by UploadPayloadBuilder.
     * @param string               $bearerToken Token from `ironcart_scan/upload/token`.
     * @param string               $userAgent   `ironcart-magento-scan/<module_version>`.
     * @param string               $allowedHost Host the URL must match (production = `ironcart.dev`).
     */
    public function post(
        string $url,
        array $payload,
        string $bearerToken,
        string $userAgent,
        string $allowedHost
    ): UploadClientResult;
}
