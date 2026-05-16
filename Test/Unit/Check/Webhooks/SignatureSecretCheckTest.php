<?php

/**
 * IronCart_Scan — IC-091 unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Webhooks;

use IronCart\Scan\Check\Webhooks\SignatureSecretCheck;
use IronCart\Scan\Check\Webhooks\WebhookSubscriptionReader;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

final class SignatureSecretCheckTest extends TestCase
{
    /**
     * Module-absent path — same shape as IC-090's silent no-op.
     */
    public function testModuleAbsentReturnsNoFindings(): void
    {
        $check = new SignatureSecretCheck(new WebhookSubscriptionReader());
        self::assertSame([], $check->run());
    }

    public function testEmptySecretIsFlagged(): void
    {
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '1',
                'name' => 'orders.created',
                'signature_secret' => '',
            ]),
            $this->subscription([
                'subscription_id' => '2',
                'name' => 'orders.shipped',
                'signature_secret' => 'present-and-long-enough',
            ]),
        ]);

        $findings = (new SignatureSecretCheck($reader))->run();

        self::assertCount(1, $findings);
        self::assertSame('IC-091', $findings[0]['id']);
        self::assertSame(Severity::HIGH, $findings[0]['severity']);
        self::assertSame(
            'https://ironcart.dev/docs/checks/IC-091',
            $findings[0]['remediation_url']
        );

        $offenders = $findings[0]['evidence']['subscriptions'];
        self::assertCount(1, $offenders);
        self::assertSame('1', $offenders[0]['subscription_id']);
        self::assertSame('orders.created', $offenders[0]['name']);
    }

    public function testNullSecretIsFlagged(): void
    {
        // The reader normalises NULL to empty string. Verify IC-091 picks
        // that up — the most common merchant misconfiguration is the
        // signature_secret column being NULL rather than '' specifically.
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '3',
                'signature_secret' => null,
            ]),
        ]);

        $findings = (new SignatureSecretCheck($reader))->run();
        self::assertCount(1, $findings);
    }

    public function testWhitespaceOnlySecretIsFlagged(): void
    {
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '4',
                'signature_secret' => "   \t\n",
            ]),
        ]);
        $findings = (new SignatureSecretCheck($reader))->run();
        self::assertCount(1, $findings);
    }

    public function testAllSecretsPresentProducesNoFinding(): void
    {
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '5',
                'signature_secret' => 'rotated-secret-1',
            ]),
            $this->subscription([
                'subscription_id' => '6',
                'signature_secret' => 'rotated-secret-2',
            ]),
        ]);
        self::assertSame([], (new SignatureSecretCheck($reader))->run());
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
