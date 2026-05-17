<?php

/**
 * IronCart_Scan — upload client result value object.
 *
 * Returned by {@see UploadClient::post()} so the runner can branch on the
 * outcome without forcing every implementation to surface the same in-band
 * error codes via exceptions or null. `category` is the stable shape stderr
 * renders against (`timeout`, `transport`, `auth`, `payload_too_large`,
 * `server`, `quota_exceeded`, `other`).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Upload;

/**
 * Result of an upload attempt.
 */
final class UploadClientResult
{
    /**
     * Categorical error label, used for stderr output without ever leaking
     * the response body verbatim.
     */
    public const CATEGORY_OK = 'ok';
    public const CATEGORY_TIMEOUT = 'timeout';
    public const CATEGORY_TRANSPORT = 'transport';
    public const CATEGORY_AUTH = 'auth';                  // 401 / 403
    public const CATEGORY_QUOTA_EXCEEDED = 'quota_exceeded'; // 402 — free-tier limit reached
    public const CATEGORY_PAYLOAD_TOO_LARGE = 'payload_too_large'; // 413
    public const CATEGORY_BAD_REQUEST = 'bad_request';    // 400 / 422
    public const CATEGORY_SERVER = 'server';              // 5xx
    public const CATEGORY_OTHER = 'other';
    public const CATEGORY_HOST_REJECTED = 'host_rejected';

    /**
     * @param int|null     $httpCode    HTTP status code if the response made it back, null on transport failure.
     * @param string|null  $viewUrl     `view_url` extracted from the 2xx body. Null on non-2xx.
     * @param string       $category    One of the CATEGORY_* constants.
     * @param string|null  $upgradeUrl  `upgrade_url` extracted from a 402 body — null otherwise. Only this
     *                                  one extra field is surfaced from non-2xx responses; everything else
     *                                  in a failure body is intentionally discarded so a misconfigured
     *                                  IronCartWeb instance can't render arbitrary stack traces into the
     *                                  operator's terminal / cron log.
     */
    public function __construct(
        public readonly ?int $httpCode,
        public readonly ?string $viewUrl,
        public readonly string $category,
        public readonly ?string $upgradeUrl = null
    ) {
    }

    public function ok(): bool
    {
        return $this->category === self::CATEGORY_OK;
    }
}
