<?php

/**
 * IronCart_Scan — fake CspProbeClient for unit tests.
 *
 * Stubs the HTTP probe so check tests can assert against fixed
 * response headers without hitting ext-curl or the network.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Runtime\Csp;

use IronCart\Scan\Check\Runtime\Csp\CspProbeClient;

/**
 * Test fake — never touches the network.
 */
final class FakeCspProbeClient implements CspProbeClient
{
    /** @var list<array{url:string, userAgent:string}> */
    public array $calls = [];

    /**
     * @param array<string, string>|null $headers  Lowercased header map
     *                                              the fake will return,
     *                                              or null to simulate a
     *                                              transport failure.
     */
    public function __construct(private readonly ?array $headers)
    {
    }

    /**
     * @inheritDoc
     */
    public function head(string $url, string $userAgent): ?array
    {
        $this->calls[] = ['url' => $url, 'userAgent' => $userAgent];

        return $this->headers;
    }
}
