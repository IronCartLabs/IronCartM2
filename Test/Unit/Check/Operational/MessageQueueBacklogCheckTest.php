<?php

/**
 * IronCart_Scan — IC-043 unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Operational;

use IronCart\Scan\Check\Operational\MessageQueueBacklogCheck;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

final class MessageQueueBacklogCheckTest extends TestCase
{
    public function testNoFactoryReturnsNoFindings(): void
    {
        $check = new MessageQueueBacklogCheck(null);
        self::assertSame([], $check->run());
    }

    public function testQueuesBelowThresholdAreIgnored(): void
    {
        $factory = $this->makeQueueFactory([
            ['name' => 'async.operations.all', 'depth' => 500],
            ['name' => 'inventory.reservations.cleanup', 'depth' => 9999],
        ]);

        $check = new MessageQueueBacklogCheck($factory);
        self::assertSame([], $check->run());
    }

    public function testQueuesAboveThresholdFlagMedium(): void
    {
        $factory = $this->makeQueueFactory([
            ['name' => 'async.operations.all', 'depth' => 500],
            ['name' => 'inventory.source.items.cleanup', 'depth' => 25_000],
            ['name' => 'product.action.attribute.update', 'depth' => 11_500],
        ]);

        $check = new MessageQueueBacklogCheck($factory);
        $findings = $check->run();

        self::assertCount(1, $findings);
        self::assertSame('IC-043', $findings[0]['id']);
        self::assertSame(Severity::MEDIUM, $findings[0]['severity']);
        self::assertSame(10_000, $findings[0]['evidence']['threshold']);
        self::assertCount(2, $findings[0]['evidence']['queues']);
    }

    /**
     * Build a stub QueueCollectionFactory whose collection yields queue
     * doubles exposing `getName()` + `getMessages()->getSize()`.
     *
     * @param list<array{name:string,depth:int}> $rows
     */
    private function makeQueueFactory(array $rows): object
    {
        $queues = [];
        foreach ($rows as $row) {
            $queues[] = new class ($row['name'], $row['depth']) {
                /** @var object{getSize: callable} */
                private object $messages;

                public function __construct(private readonly string $name, int $depth)
                {
                    $this->messages = new class ($depth) {
                        public function __construct(private readonly int $size)
                        {
                        }

                        public function getSize(): int
                        {
                            return $this->size;
                        }
                    };
                }

                public function getName(): string
                {
                    return $this->name;
                }

                public function getMessages(): object
                {
                    return $this->messages;
                }
            };
        }

        $collection = new class ($queues) implements \IteratorAggregate {
            /** @param list<object> $queues */
            public function __construct(private readonly array $queues)
            {
            }

            public function getIterator(): \Iterator
            {
                return new \ArrayIterator($this->queues);
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
}
