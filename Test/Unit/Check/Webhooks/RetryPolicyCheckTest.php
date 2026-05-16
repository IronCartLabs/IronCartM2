<?php

/**
 * IronCart_Scan — IC-092 unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Webhooks;

use IronCart\Scan\Check\Webhooks\RetryPolicyCheck;
use IronCart\Scan\Check\Webhooks\WebhookSubscriptionReader;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

final class RetryPolicyCheckTest extends TestCase
{
    public function testModuleAbsentReturnsNoFindings(): void
    {
        $check = new RetryPolicyCheck(new WebhookSubscriptionReader());
        self::assertSame([], $check->run());
    }

    public function testExcessiveMaxRetriesIsFlaggedMedium(): void
    {
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '1',
                'name' => 'orders.created',
                'max_retries' => 500,
                'retry_backoff' => 60,
            ]),
        ]);

        $findings = (new RetryPolicyCheck($reader))->run();

        self::assertCount(1, $findings);
        self::assertSame('IC-092', $findings[0]['id']);
        self::assertSame(Severity::MEDIUM, $findings[0]['severity']);
        self::assertSame(
            RetryPolicyCheck::MAX_RETRIES_THRESHOLD,
            $findings[0]['evidence']['max_retries_threshold']
        );
        $offender = $findings[0]['evidence']['subscriptions'][0];
        self::assertSame('1', $offender['subscription_id']);
        self::assertSame(500, $offender['max_retries']);
        self::assertCount(1, $offender['reasons']);
    }

    public function testTooShortBackoffIsFlaggedMedium(): void
    {
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '2',
                'max_retries' => 5,
                'retry_backoff' => 1,
            ]),
        ]);

        $findings = (new RetryPolicyCheck($reader))->run();
        self::assertCount(1, $findings);
        $offender = $findings[0]['evidence']['subscriptions'][0];
        self::assertSame(1, $offender['retry_backoff']);
        self::assertCount(1, $offender['reasons']);
    }

    public function testBothKnobsBadEmitsBothReasons(): void
    {
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '3',
                'max_retries' => 1_000,
                'retry_backoff' => 2,
            ]),
        ]);
        $findings = (new RetryPolicyCheck($reader))->run();
        $offender = $findings[0]['evidence']['subscriptions'][0];
        self::assertCount(2, $offender['reasons']);
    }

    public function testZeroBackoffTreatedAsUnsetNotShortcoming(): void
    {
        // A retry_backoff of 0 is the reader's normalisation of NULL or
        // unset — it means "field not configured", not "configured at
        // sub-second". IC-092 only flags retry_backoff that is explicitly
        // configured below the minimum.
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '4',
                'max_retries' => 5,
                'retry_backoff' => 0,
            ]),
        ]);
        self::assertSame([], (new RetryPolicyCheck($reader))->run());
    }

    public function testAtThresholdIsNotFlagged(): void
    {
        // At the threshold (=100) — not above — is still acceptable.
        $reader = $this->readerWith([
            $this->subscription([
                'subscription_id' => '5',
                'max_retries' => RetryPolicyCheck::MAX_RETRIES_THRESHOLD,
                'retry_backoff' => RetryPolicyCheck::MIN_RETRY_BACKOFF_SECONDS,
            ]),
        ]);
        self::assertSame([], (new RetryPolicyCheck($reader))->run());
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
