<?php

/**
 * IronCart_Scan — CurlUploadClient unit tests.
 *
 * These tests exercise the production {@see CurlUploadClient} directly
 * (no mocking) so we can't accidentally green on a typo in the host-pin
 * comparison. We never open a real socket — the host-pin rejection runs
 * in pure PHP before `curl_exec` is invoked, so test cases that target
 * non-ironcart.dev URLs are safe.
 *
 * The 2xx/4xx/5xx response-decoding paths are exercised via the runner
 * test using {@see FakeUploadClient} — testing them against the real
 * cURL client would require a live HTTP server.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Upload;

use IronCart\Scan\Check\Upload\CurlUploadClient;
use IronCart\Scan\Check\Upload\UploadClient;
use IronCart\Scan\Check\Upload\UploadClientResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\Upload\CurlUploadClient
 */
class CurlUploadClientTest extends TestCase
{
    public function testAllowedHostConstantIsPinnedToIroncartDev(): void
    {
        // Lock the production allow-list to a known string so the bot
        // can't silently switch hosts via a refactor.
        self::assertSame('ironcart.dev', UploadClient::ALLOWED_HOST);
    }

    public function testHostMismatchRejectsBeforeAnySocket(): void
    {
        $client = new CurlUploadClient();

        $result = $client->post(
            'https://evil.com/api/scan/ingest',
            ['schema_version' => '1'],
            'token',
            'ironcart-magento-scan/test',
            'ironcart.dev'
        );

        self::assertSame(UploadClientResult::CATEGORY_HOST_REJECTED, $result->category);
        self::assertNull($result->httpCode);
        self::assertNull($result->viewUrl);
    }

    public function testHostMismatchRejectsSubdomainShellGame(): void
    {
        // `evil.ironcart.dev.attacker.com` must NOT match an exact-string
        // allow-list. This is the classic suffix-match bypass.
        $client = new CurlUploadClient();

        $result = $client->post(
            'https://evil.ironcart.dev.attacker.com/api/scan/ingest',
            [],
            'token',
            'ua',
            'ironcart.dev'
        );

        self::assertSame(UploadClientResult::CATEGORY_HOST_REJECTED, $result->category);
    }

    public function testMalformedUrlIsRejected(): void
    {
        $client = new CurlUploadClient();

        $result = $client->post(
            'not a url at all',
            [],
            'token',
            'ua',
            'ironcart.dev'
        );

        self::assertSame(UploadClientResult::CATEGORY_HOST_REJECTED, $result->category);
    }

    public function testCustomAllowedHostIsHonoured(): void
    {
        // Staging / QA path: when allowedHost is overridden to a non-
        // ironcart.dev value, the client must honour the override (and
        // still reject anything else).
        $client = new CurlUploadClient();

        $rejected = $client->post(
            'https://ironcart.dev/api/scan/ingest',
            [],
            'token',
            'ua',
            'localhost'
        );
        self::assertSame(UploadClientResult::CATEGORY_HOST_REJECTED, $rejected->category);
    }
}
