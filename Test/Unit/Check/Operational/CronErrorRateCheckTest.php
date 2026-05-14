<?php

/**
 * IronCart_Scan — IC-042 unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Operational;

use IronCart\Scan\Check\Operational\CronErrorRateCheck;
use IronCart\Scan\Report\Severity;
use Magento\Cron\Model\ResourceModel\Schedule\Collection as CronCollection;
use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory as CronCollectionFactory;
use Magento\Cron\Model\Schedule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CronErrorRateCheckTest extends TestCase
{
    public function testHealthyRateReturnsNoFindings(): void
    {
        $schedules = $this->makeSchedules(array_merge(
            array_fill(0, 98, Schedule::STATUS_SUCCESS),
            [Schedule::STATUS_ERROR, Schedule::STATUS_MISSED]
        )); // 2% failure rate

        $check = new CronErrorRateCheck($this->mockFactory($schedules));
        self::assertSame([], $check->run());
    }

    public function testUnhealthyRateFlagsMedium(): void
    {
        $schedules = $this->makeSchedules(array_merge(
            array_fill(0, 90, Schedule::STATUS_SUCCESS),
            array_fill(0, 6, Schedule::STATUS_ERROR),
            array_fill(0, 4, Schedule::STATUS_MISSED)
        )); // 10% failure rate

        $check = new CronErrorRateCheck($this->mockFactory($schedules));
        $findings = $check->run();

        self::assertCount(1, $findings);
        self::assertSame('IC-042', $findings[0]['id']);
        self::assertSame(Severity::MEDIUM, $findings[0]['severity']);
        self::assertSame(100, $findings[0]['evidence']['sample_size']);
        self::assertSame(10, $findings[0]['evidence']['failed']);
        self::assertSame(10.0, $findings[0]['evidence']['failed_pct']);
    }

    public function testEmptyTableReturnsNoFindings(): void
    {
        $check = new CronErrorRateCheck($this->mockFactory([]));
        self::assertSame([], $check->run());
    }

    /**
     * @param list<string> $statuses
     * @return list<Schedule>
     */
    private function makeSchedules(array $statuses): array
    {
        $rows = [];
        foreach ($statuses as $status) {
            $schedule = $this->createMock(Schedule::class);
            $schedule->method('getStatus')->willReturn($status);
            $rows[] = $schedule;
        }

        return $rows;
    }

    /**
     * @param list<Schedule> $rows
     */
    private function mockFactory(array $rows): CronCollectionFactory&MockObject
    {
        $collection = $this->createMock(CronCollection::class);
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('setCurPage')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rows));

        $factory = $this->createMock(CronCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }
}
