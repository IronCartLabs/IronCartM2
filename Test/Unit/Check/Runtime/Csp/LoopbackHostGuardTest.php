<?php

/**
 * IronCart_Scan — LoopbackHostGuard unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Runtime\Csp;

use IronCart\Scan\Check\Runtime\Csp\LoopbackHostGuard;
use PHPUnit\Framework\TestCase;

class LoopbackHostGuardTest extends TestCase
{
    /**
     * @dataProvider allowedHosts
     */
    public function testAllowedHosts(string $url, string $baseUrl): void
    {
        $this->assertTrue(LoopbackHostGuard::isAllowed($url, $baseUrl));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function allowedHosts(): array
    {
        return [
            ['http://localhost/', 'http://localhost/'],
            ['http://127.0.0.1/', 'http://127.0.0.1/'],
            ['http://[::1]/', 'http://[::1]/'],
            ['http://10.0.0.5/', 'http://10.0.0.5/'],
            ['http://192.168.1.20/', 'http://192.168.1.20/'],
            ['http://172.16.0.1/', 'http://172.16.0.1/'],
            ['http://169.254.1.1/', 'http://169.254.1.1/'],
            ['http://magento.test/', 'http://magento.test/'],
            ['https://store.example.com/', 'https://store.example.com/'],
            // *.localhost is treated as loopback per RFC 6761.
            ['http://magento.localhost/', 'http://magento.localhost/'],
        ];
    }

    /**
     * @dataProvider rejectedHosts
     */
    public function testRejectedHosts(string $url, string $baseUrl): void
    {
        $this->assertFalse(LoopbackHostGuard::isAllowed($url, $baseUrl));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function rejectedHosts(): array
    {
        return [
            // Public IP — not in any private/reserved range.
            ['http://8.8.8.8/', 'http://store.example.com/'],
            // Wrong public hostname — base URL says one thing, probe says another.
            ['http://attacker.example/', 'http://store.example.com/'],
            // Unparseable.
            ['', 'http://store.example.com/'],
            ['not a url', 'http://store.example.com/'],
        ];
    }

    public function testExtractHostStripsIpv6Brackets(): void
    {
        $this->assertSame('::1', LoopbackHostGuard::extractHost('http://[::1]/path'));
    }

    public function testExtractHostLowercases(): void
    {
        $this->assertSame(
            'store.example.com',
            LoopbackHostGuard::extractHost('https://Store.Example.COM/')
        );
    }

    public function testExtractHostNullOnEmpty(): void
    {
        $this->assertNull(LoopbackHostGuard::extractHost(''));
        $this->assertNull(LoopbackHostGuard::extractHost('   '));
        $this->assertNull(LoopbackHostGuard::extractHost('not-a-url'));
    }
}
