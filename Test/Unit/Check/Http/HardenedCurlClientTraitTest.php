<?php

/**
 * IronCart_Scan — HardenedCurlClientTrait unit tests.
 *
 * Exercises the trait's two static behaviours:
 *
 *   - `hostMatches` — the exact-string, case-insensitive host allow-list
 *     comparison shared by the CVE proxy + upload clients. We verify the
 *     four classic SSRF bypass attempts (different host, suffix attack,
 *     malformed URL, empty string).
 *
 *   - `assertRequiredOverrides` — the fail-fast LogicException raised
 *     when a subclass forgets to pin one of the four mandatory options
 *     (PROTOCOLS, REDIR_PROTOCOLS, TIMEOUT, CONNECTTIMEOUT). This is the
 *     guardrail that prevents a future refactor from silently inheriting
 *     libcurl's unsafe defaults.
 *
 * We do not exercise `applyHardenedOptions` end-to-end because cURL
 * `curl_getinfo` does not report back the options set on the handle.
 * The integration job (`bin/magento ironcart:scan --upload` end-to-end
 * inside the Magento sandbox) is the canonical gate for the live cURL
 * code path.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Http;

use CurlHandle;
use IronCart\Scan\Check\Http\HardenedCurlClientTrait;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\Http\HardenedCurlClientTrait
 */
class HardenedCurlClientTraitTest extends TestCase
{
    public function testHostMatchesAcceptsExactCaseInsensitive(): void
    {
        $client = $this->newHarness();

        self::assertTrue($client->callHostMatches('https://ironcart.dev/api/cve', 'ironcart.dev'));
        // Case fold — `parse_url` returns the host verbatim from the URL
        // so the comparison must be case-insensitive.
        self::assertTrue($client->callHostMatches('https://IRONCART.DEV/api/cve', 'ironcart.dev'));
        self::assertTrue($client->callHostMatches('https://ironcart.dev/api/cve', 'IRONCART.DEV'));
    }

    public function testHostMatchesRejectsDifferentHost(): void
    {
        $client = $this->newHarness();

        self::assertFalse($client->callHostMatches('https://evil.com/api/cve', 'ironcart.dev'));
    }

    public function testHostMatchesRejectsSuffixShellGame(): void
    {
        // Classic suffix-match bypass — must NOT match an exact-string
        // allow-list.
        $client = $this->newHarness();

        self::assertFalse($client->callHostMatches(
            'https://evil.ironcart.dev.attacker.com/api/cve',
            'ironcart.dev'
        ));
    }

    public function testHostMatchesRejectsSubdomain(): void
    {
        // `staging.ironcart.dev` is a different host. The trait is an
        // EXACT-string allow-list. Subdomain handling, if ever wanted,
        // must be opt-in at the call site.
        $client = $this->newHarness();

        self::assertFalse($client->callHostMatches(
            'https://staging.ironcart.dev/api/cve',
            'ironcart.dev'
        ));
    }

    public function testHostMatchesRejectsMalformedUrl(): void
    {
        $client = $this->newHarness();

        self::assertFalse($client->callHostMatches('not a url at all', 'ironcart.dev'));
        self::assertFalse($client->callHostMatches('', 'ironcart.dev'));
        self::assertFalse($client->callHostMatches('   ', 'ironcart.dev'));
        // Schemeless — parse_url returns null host.
        self::assertFalse($client->callHostMatches('/api/cve', 'ironcart.dev'));
    }

    public function testApplyHardenedOptionsRequiresProtocols(): void
    {
        $client = $this->newHarness();
        $ch = curl_init();
        self::assertInstanceOf(CurlHandle::class, $ch);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/CURLOPT_PROTOCOLS/');

        $client->callApplyHardenedOptions($ch, [
            // PROTOCOLS deliberately missing.
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 5,
        ]);
    }

    public function testApplyHardenedOptionsRequiresRedirProtocols(): void
    {
        $client = $this->newHarness();
        $ch = curl_init();
        self::assertInstanceOf(CurlHandle::class, $ch);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/CURLOPT_REDIR_PROTOCOLS/');

        $client->callApplyHardenedOptions($ch, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            // REDIR_PROTOCOLS deliberately missing.
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 5,
        ]);
    }

    public function testApplyHardenedOptionsRequiresConnectTimeout(): void
    {
        $client = $this->newHarness();
        $ch = curl_init();
        self::assertInstanceOf(CurlHandle::class, $ch);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/CURLOPT_CONNECTTIMEOUT/');

        $client->callApplyHardenedOptions($ch, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            // CONNECTTIMEOUT deliberately missing.
            CURLOPT_TIMEOUT => 5,
        ]);
    }

    public function testApplyHardenedOptionsRequiresTimeout(): void
    {
        $client = $this->newHarness();
        $ch = curl_init();
        self::assertInstanceOf(CurlHandle::class, $ch);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/CURLOPT_TIMEOUT/');

        $client->callApplyHardenedOptions($ch, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            // TIMEOUT deliberately missing.
        ]);
    }

    public function testApplyHardenedOptionsAcceptsAllRequired(): void
    {
        // The happy path: every required override present, no exception.
        $client = $this->newHarness();
        $ch = curl_init();
        self::assertInstanceOf(CurlHandle::class, $ch);

        $client->callApplyHardenedOptions($ch, [
            CURLOPT_URL => 'https://ironcart.dev/api/cve',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'test',
        ]);

        // No curl_close() — deprecated in PHP 8.5, no-op since PHP 8.0.
        $this->expectNotToPerformAssertions();
    }

    public function testApplyHardenedOptionsReapplyOverridesUnsafeOverride(): void
    {
        // The reviewer-visible guarantee: if a buggy subclass passes
        // `CURLOPT_FOLLOWLOCATION => true` in its overrides array, the
        // trait re-applies `=> false` afterwards. We can't introspect the
        // handle to assert the final value (curl_getinfo doesn't report
        // setopt values), so this test exists for documentation: the
        // re-apply step is intentional. The behavioural guarantee is
        // enforced by code review of HardenedCurlClientTrait::applyHardenedOptions.
        $client = $this->newHarness();
        $ch = curl_init();
        self::assertInstanceOf(CurlHandle::class, $ch);

        // Should not throw — the override is malformed but valid syntax.
        $client->callApplyHardenedOptions($ch, [
            CURLOPT_URL => 'https://ironcart.dev/api/cve',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'test',
            // The "I forgot we don't follow redirects" mistake. The trait
            // un-sets this on the re-apply pass.
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIE => 'sessionid=leaked',
        ]);

        // No curl_close() — deprecated in PHP 8.5, no-op since PHP 8.0.
        $this->expectNotToPerformAssertions();
    }

    /**
     * Build an anonymous class that mixes the trait in and exposes the
     * protected methods as public for the test. We can't `use` a trait
     * directly in a test method body, so the harness is the minimum
     * viable container.
     */
    private function newHarness(): object
    {
        return new class {
            use HardenedCurlClientTrait;

            public function callHostMatches(string $url, string $allowedHost): bool
            {
                return self::hostMatches($url, $allowedHost);
            }

            /**
             * @param array<int, mixed> $overrides
             */
            public function callApplyHardenedOptions(CurlHandle $ch, array $overrides): void
            {
                $this->applyHardenedOptions($ch, $overrides);
            }
        };
    }
}
