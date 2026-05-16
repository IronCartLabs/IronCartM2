<?php

/**
 * IronCart_Scan — WebhookSubscriptionReader unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Webhooks;

use IronCart\Scan\Check\Webhooks\WebhookSubscriptionReader;
use PHPUnit\Framework\TestCase;

final class WebhookSubscriptionReaderTest extends TestCase
{
    /**
     * Module-absent path: the unit cell strips magento/framework, so the
     * Adobe Commerce Webhooks subscription collection factory does not
     * class_exists() and ObjectManager is never consulted. The reader
     * must NOT throw and must report the module as absent.
     */
    public function testDefaultConstructionReportsModuleAbsent(): void
    {
        $reader = new WebhookSubscriptionReader();
        self::assertFalse($reader->isWebhooksModulePresent());
        self::assertSame([], $reader->all());
    }

    public function testInjectedFactoryReportsModulePresent(): void
    {
        $reader = new WebhookSubscriptionReader($this->makeFactory([]));
        self::assertTrue($reader->isWebhooksModulePresent());
        self::assertSame([], $reader->all());
    }

    public function testNormalisesGetDataBackedSubscription(): void
    {
        $factory = $this->makeFactory([
            $this->makeSubscriptionViaGetData([
                'subscription_id' => '7',
                'name' => 'orders.created',
                'destination_url' => 'https://partner.example.com/hook',
                'signature_secret' => 'shhh',
                'max_retries' => 5,
                'retry_backoff' => 30,
            ]),
        ]);

        $rows = (new WebhookSubscriptionReader($factory))->all();

        self::assertCount(1, $rows);
        self::assertSame('7', $rows[0]['subscription_id']);
        self::assertSame('orders.created', $rows[0]['name']);
        self::assertSame('https://partner.example.com/hook', $rows[0]['destination_url']);
        self::assertSame('shhh', $rows[0]['signature_secret']);
        self::assertSame(5, $rows[0]['max_retries']);
        self::assertSame(30, $rows[0]['retry_backoff']);
    }

    public function testNormalisesNullSecretToEmptyString(): void
    {
        $factory = $this->makeFactory([
            $this->makeSubscriptionViaGetData([
                'subscription_id' => '8',
                'name' => 'orders.shipped',
                'destination_url' => 'https://partner.example.com/hook',
                'signature_secret' => null,
                'max_retries' => null,
                'retry_backoff' => null,
            ]),
        ]);

        $rows = (new WebhookSubscriptionReader($factory))->all();

        self::assertSame('', $rows[0]['signature_secret']);
        self::assertSame(0, $rows[0]['max_retries']);
        self::assertSame(0, $rows[0]['retry_backoff']);
    }

    /**
     * Stub factory whose create() returns a Traversable of the given
     * subscription doubles.
     *
     * @param list<object> $subscriptions
     */
    public static function makeFactory(array $subscriptions): object
    {
        $collection = new class ($subscriptions) implements \IteratorAggregate {
            /** @param list<object> $rows */
            public function __construct(private readonly array $rows)
            {
            }

            public function getIterator(): \Iterator
            {
                return new \ArrayIterator($this->rows);
            }
        };

        return new class ($collection) {
            public function __construct(private readonly object $collection)
            {
            }

            public function create(): object
            {
                return $this->collection;
            }
        };
    }

    /**
     * Build a subscription stub that exposes `getData($field)` — the
     * canonical Magento AbstractModel accessor.
     *
     * @param array<string,mixed> $data
     */
    public static function makeSubscriptionViaGetData(array $data): object
    {
        return new class ($data) {
            /** @param array<string,mixed> $data */
            public function __construct(private readonly array $data)
            {
            }

            public function getData(string $field): mixed
            {
                return $this->data[$field] ?? null;
            }
        };
    }
}
