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
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;

final class MessageQueueBacklogCheckTest extends TestCase
{
    public function testMissingFactoryClassReturnsNoFindings(): void
    {
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->expects(self::never())->method('get');

        $check = new MessageQueueBacklogCheck(
            $objectManager,
            'Definitely\\Not\\A\\Real\\Class\\NonExistent_' . uniqid()
        );
        self::assertSame([], $check->run());
    }

    public function testQueuesBelowThresholdAreIgnored(): void
    {
        $factory = $this->makeQueueFactory([
            ['name' => 'async.operations.all', 'depth' => 500],
            ['name' => 'inventory.reservations.cleanup', 'depth' => 9999],
        ]);

        $check = $this->makeCheckWithFactory($factory);
        self::assertSame([], $check->run());
    }

    public function testQueuesAboveThresholdFlagMedium(): void
    {
        $factory = $this->makeQueueFactory([
            ['name' => 'async.operations.all', 'depth' => 500],
            ['name' => 'inventory.source.items.cleanup', 'depth' => 25_000],
            ['name' => 'product.action.attribute.update', 'depth' => 11_500],
        ]);

        $check = $this->makeCheckWithFactory($factory);
        $findings = $check->run();

        self::assertCount(1, $findings);
        self::assertSame('IC-043', $findings[0]['id']);
        self::assertSame(Severity::MEDIUM, $findings[0]['severity']);
        self::assertSame(10_000, $findings[0]['evidence']['threshold']);
        self::assertCount(2, $findings[0]['evidence']['queues']);
    }

    /**
     * Build a check wired to a real (test-only) factory class so the
     * production `class_exists` guard passes and ObjectManager returns the
     * stub.
     */
    private function makeCheckWithFactory(object $factory): MessageQueueBacklogCheck
    {
        $factoryClass = $factory::class;
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->with($factoryClass)->willReturn($factory);

        return new MessageQueueBacklogCheck($objectManager, $factoryClass);
    }

    /**
     * Build a stub QueueCollectionFactory whose collection yields queue
     * doubles exposing `getName()` + `getMessages()->getSize()`.
     *
     * @param list<array{name:string,depth:int}> $rows
     */
    private function makeQueueFactory(array $rows): StubQueueCollectionFactory
    {
        $queues = [];
        foreach ($rows as $row) {
            $queues[] = new class ($row['name'], $row['depth']) {
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

        return new StubQueueCollectionFactory($collection);
    }
}

/**
 * Real (named) test-only class so `class_exists()` in the production guard
 * returns true and ObjectManager has a concrete FQCN to resolve.
 */
final class StubQueueCollectionFactory
{
    public function __construct(private readonly object $collection)
    {
    }

    public function create(): object
    {
        return $this->collection;
    }
}
