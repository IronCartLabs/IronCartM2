<?php

/**
 * IronCart_Scan — fake UploadClient for tests.
 *
 * Mirrors the production host-pin so the runner / payload tests can't
 * accidentally green on a typo in the production allow-list constant.
 * The fake records every invocation so individual tests can assert on
 * payload shape and request headers without driving real cURL.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Upload;

use IronCart\Scan\Check\Upload\UploadClient;
use IronCart\Scan\Check\Upload\UploadClientResult;

/**
 * Recording in-memory fake of {@see UploadClient}.
 */
class FakeUploadClient implements UploadClient
{
    /**
     * @var list<array{
     *     url:string,
     *     payload:array<string,mixed>,
     *     token:string,
     *     userAgent:string,
     *     allowedHost:string
     * }>
     */
    public array $invocations = [];

    /**
     * Queued responses, returned in order. If the queue is empty, a 200
     * OK with no `view_url` is returned by default — convenient for the
     * happy-path runner tests.
     *
     * @var list<UploadClientResult>
     */
    public array $queuedResponses = [];

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
        $this->invocations[] = [
            'url' => $url,
            'payload' => $payload,
            'token' => $bearerToken,
            'userAgent' => $userAgent,
            'allowedHost' => $allowedHost,
        ];

        // Mirror the production host check so a test that forgets to
        // swap the endpoint can't accidentally green.
        $host = parse_url(trim($url), PHP_URL_HOST);
        if (!is_string($host) || strcasecmp($host, $allowedHost) !== 0) {
            return new UploadClientResult(null, null, UploadClientResult::CATEGORY_HOST_REJECTED);
        }

        if ($this->queuedResponses !== []) {
            return array_shift($this->queuedResponses);
        }

        return new UploadClientResult(200, 'https://ironcart.dev/scan/abc123', UploadClientResult::CATEGORY_OK);
    }
}
