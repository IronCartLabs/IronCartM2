<?php

/**
 * IronCart_Scan — fake CveProxyClient for unit tests.
 *
 * Implements the same `ironcart.dev`-only host-check the production
 * client enforces so tests can assert against the rejection path
 * without touching ext-curl. Calls are recorded for later assertions.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Cve;

use IronCart\Scan\Check\Cve\CveProxyClient;

/**
 * Test fake — never touches the network.
 */
final class FakeCveProxyClient implements CveProxyClient
{
    /** @var list<array{url:string, payload:array<string, mixed>, userAgent:string}> */
    public array $calls = [];

    /**
     * @param list<array<string, mixed>|null> $responses
     *        Queue of responses returned by successive `post()` calls.
     *        A null entry simulates a transport failure for that call.
     */
    public function __construct(private array $responses = [])
    {
    }

    /**
     * @inheritDoc
     */
    public function post(string $url, array $payload, string $userAgent): ?array
    {
        // Enforce the same host allowlist as production — host-check
        // failures must NOT count as recorded calls because the
        // production client also refuses to open a socket. This lets
        // the host-rejection test assert `calls === []`.
        $host = parse_url(trim($url), PHP_URL_HOST);
        if (!is_string($host) || strcasecmp($host, self::ALLOWED_HOST) !== 0) {
            return null;
        }

        $this->calls[] = [
            'url' => $url,
            'payload' => $payload,
            'userAgent' => $userAgent,
        ];

        if ($this->responses === []) {
            return null;
        }
        return array_shift($this->responses);
    }
}
