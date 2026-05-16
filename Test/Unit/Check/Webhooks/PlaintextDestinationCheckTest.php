<?php

/**
 * IronCart_Scan — IC-090 unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Webhooks;

use IronCart\Scan\Check\Webhooks\PlaintextDestinationCheck;
use IronCart\Scan\Check\Webhooks\WebhookSubscriptionReader;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

final class PlaintextDestinationCheckTest extends TestCase
{
    /**
     * Module-absent path: with no injected factory the reader treats
     * Adobe Commerce Webhooks as not installed (the unit cell has no
     * magento/framework on the classpath) and the check returns no
     * findings — exactly the silent no-op the v0 schema requires.
     */
    public function testModuleAbsentReturnsNoFindings(): void
    {
        $check = new PlaintextDestinationCheck(new WebhookSubscriptionReader());
        self::assertSame([], $check->run());
    }

    public function testPlaintextHttpSubscriptionIsFlagged(): void
    {
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '1',
                'name' => 'orders.created',
                'destination_url' => 'http://partner.example.com/hook',
            ]),
            $this->subscription([
                'subscription_id' => '2',
                'name' => 'orders.shipped',
                'destination_url' => 'https://partner.example.com/hook',
            ]),
        ]);

        $findings = (new PlaintextDestinationCheck($reader))->run();

        self::assertCount(1, $findings);
        self::assertSame('IC-090', $findings[0]['id']);
        self::assertSame(Severity::HIGH, $findings[0]['severity']);
        self::assertSame(
            'https://ironcart.dev/docs/checks/IC-090',
            $findings[0]['remediation_url']
        );

        $offenders = $findings[0]['evidence']['subscriptions'];
        self::assertCount(1, $offenders);
        self::assertSame('1', $offenders[0]['subscription_id']);
        self::assertSame('orders.created', $offenders[0]['name']);
        self::assertSame('http://partner.example.com/hook', $offenders[0]['destination_url']);
    }

    public function testAllHttpsSubscriptionsProduceNoFinding(): void
    {
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '1',
                'destination_url' => 'https://partner.example.com/hook',
            ]),
            $this->subscription([
                'subscription_id' => '2',
                'destination_url' => 'HTTPS://capslock.example.com/hook',
            ]),
        ]);

        self::assertSame([], (new PlaintextDestinationCheck($reader))->run());
    }

    public function testCaseInsensitiveSchemeStillFlagged(): void
    {
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '3',
                'destination_url' => 'HTTP://capslock.example.com/hook',
            ]),
        ]);

        $findings = (new PlaintextDestinationCheck($reader))->run();
        self::assertCount(1, $findings);
        self::assertSame(Severity::HIGH, $findings[0]['severity']);
    }

    public function testTemplateUrlIsIgnored(): void
    {
        // Template URLs have no parseable scheme. We deliberately skip
        // rather than flag — the runtime substitution decides the scheme.
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '4',
                'destination_url' => '{$webhook_base}/orders/created',
            ]),
        ]);
        self::assertSame([], (new PlaintextDestinationCheck($reader))->run());
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
