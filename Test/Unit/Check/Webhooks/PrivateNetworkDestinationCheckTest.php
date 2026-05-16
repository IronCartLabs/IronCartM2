<?php

/**
 * IronCart_Scan — IC-093 unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Webhooks;

use IronCart\Scan\Check\Webhooks\PrivateNetworkDestinationCheck;
use IronCart\Scan\Check\Webhooks\WebhookSubscriptionReader;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

final class PrivateNetworkDestinationCheckTest extends TestCase
{
    public function testModuleAbsentReturnsNoFindings(): void
    {
        $check = new PrivateNetworkDestinationCheck(new WebhookSubscriptionReader());
        self::assertSame([], $check->run());
    }

    public function testRfc1918DestinationIsFlagged(): void
    {
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '1',
                'name' => 'orders.created',
                'destination_url' => 'https://internal.example.com/hook',
            ]),
        ]);
        $resolver = static fn (string $host): array => ['10.0.0.7'];

        $findings = (new PrivateNetworkDestinationCheck($reader, $resolver))->run();

        self::assertCount(1, $findings);
        self::assertSame('IC-093', $findings[0]['id']);
        self::assertSame(Severity::MEDIUM, $findings[0]['severity']);
        $offender = $findings[0]['evidence']['subscriptions'][0];
        self::assertSame('1', $offender['subscription_id']);
        self::assertSame('internal.example.com', $offender['hostname']);
        self::assertSame(['10.0.0.7'], $offender['private_addresses']);
    }

    public function testLoopbackDestinationIsFlagged(): void
    {
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '2',
                'destination_url' => 'http://localhost.localdomain/hook',
            ]),
        ]);
        $resolver = static fn (string $host): array => ['127.0.0.1'];

        $findings = (new PrivateNetworkDestinationCheck($reader, $resolver))->run();
        self::assertCount(1, $findings);
        self::assertSame(['127.0.0.1'], $findings[0]['evidence']['subscriptions'][0]['private_addresses']);
    }

    public function testLinkLocalIpv6DestinationIsFlagged(): void
    {
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '3',
                'destination_url' => 'https://router.local/hook',
            ]),
        ]);
        $resolver = static fn (string $host): array => ['fe80::1'];

        $findings = (new PrivateNetworkDestinationCheck($reader, $resolver))->run();
        self::assertCount(1, $findings);
    }

    public function testPublicDestinationProducesNoFinding(): void
    {
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '4',
                'destination_url' => 'https://partner.example.com/hook',
            ]),
        ]);
        $resolver = static fn (string $host): array => ['8.8.8.8'];

        self::assertSame([], (new PrivateNetworkDestinationCheck($reader, $resolver))->run());
    }

    /**
     * DNS-fail path: the resolver returns an empty list (NXDOMAIN, timeout,
     * etc.). The check must skip silently and NOT emit a "DNS unavailable"
     * finding — that's an explicit acceptance criterion on issue #49.
     */
    public function testDnsFailureIsSilent(): void
    {
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '5',
                'destination_url' => 'https://nxdomain.example.invalid/hook',
            ]),
            $this->subscription([
                'subscription_id' => '6',
                'destination_url' => 'https://partner.example.com/hook',
            ]),
        ]);
        // Resolver returns empty for the first host, public IP for the second.
        $resolver = static fn (string $host): array
            => $host === 'partner.example.com' ? ['8.8.8.8'] : [];

        self::assertSame(
            [],
            (new PrivateNetworkDestinationCheck($reader, $resolver))->run()
        );
    }

    public function testTemplateDestinationIsSkipped(): void
    {
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '7',
                'destination_url' => 'https://{$tenant}.internal.example.com/hook',
            ]),
        ]);
        $resolver = static function (string $host): array {
            // If the check tries to resolve a placeholder we want the test
            // to surface that — return a private IP so any leakage flags.
            return ['10.0.0.7'];
        };

        self::assertSame(
            [],
            (new PrivateNetworkDestinationCheck($reader, $resolver))->run()
        );
    }

    public function testLiteralPrivateIpInUrlIsFlaggedWithoutDns(): void
    {
        // No resolver invocation should happen for a literal IP — the
        // check shortcircuits. We assert that with a resolver that fails
        // the test if called.
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '8',
                'destination_url' => 'http://192.168.1.5:8080/hook',
            ]),
        ]);
        $resolver = function (string $host): array {
            self::fail('DNS resolver should not be invoked for literal IPs (got ' . $host . ')');
        };

        $findings = (new PrivateNetworkDestinationCheck($reader, $resolver))->run();
        self::assertCount(1, $findings);
        self::assertSame(['192.168.1.5'], $findings[0]['evidence']['subscriptions'][0]['private_addresses']);
    }

    public function testMixOfPublicAndPrivateAddressesFlagsOnlyPrivate(): void
    {
        // dual-stack hostname returns one public IPv4 and one private IPv6
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '9',
                'destination_url' => 'https://dual-stack.example.com/hook',
            ]),
        ]);
        $resolver = static fn (string $host): array => ['8.8.8.8', 'fc00::1'];

        $findings = (new PrivateNetworkDestinationCheck($reader, $resolver))->run();
        self::assertCount(1, $findings);
        self::assertSame(
            ['fc00::1'],
            $findings[0]['evidence']['subscriptions'][0]['private_addresses']
        );
    }

    private function readerWith(array $subscriptions): WebhookSubscriptionReader
    {
        return new WebhookSubscriptionReader(
            WebhookSubscriptionReaderTest::makeFactory($subscriptions)
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private function subscription(array $data): object
    {
        return WebhookSubscriptionReaderTest::makeSubscriptionViaGetData($data);
    }
}
