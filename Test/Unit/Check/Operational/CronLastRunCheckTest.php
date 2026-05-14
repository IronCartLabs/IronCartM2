<?php

/**
 * IronCart_Scan — IC-041 unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Operational;

use IronCart\Scan\Check\Operational\CronLastRunCheck;
use IronCart\Scan\Report\Severity;
use Magento\Cron\Model\ResourceModel\Schedule\Collection as CronCollection;
use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory as CronCollectionFactory;
use Magento\Cron\Model\Schedule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CronLastRunCheckTest extends TestCase
{
    public function testRecentRunReturnsNoFindings(): void
    {
        $rows = [
            $this->makeRow('catalog_product_alert', gmdate('Y-m-d H:i:s', time() - 60)),
        ];

        $check = new CronLastRunCheck($this->mockFactory($rows));
        self::assertSame([], $check->run());
    }

    public function testStaleRunFlagsHigh(): void
    {
        $rows = [
            $this->makeRow('catalog_product_alert', gmdate('Y-m-d H:i:s', time() - 7200)), // 2h ago
        ];

        $check = new CronLastRunCheck($this->mockFactory($rows));
        $findings = $check->run();

        self::assertCount(1, $findings);
        self::assertSame('IC-041', $findings[0]['id']);
        self::assertSame(Severity::HIGH, $findings[0]['severity']);
        self::assertSame('default', $findings[0]['evidence']['group']);
        self::assertSame('catalog_product_alert', $findings[0]['evidence']['last_job_code']);
        self::assertGreaterThanOrEqual(7200, $findings[0]['evidence']['age_seconds']);
    }

    public function testEmptyCollectionFlagsHigh(): void
    {
        $check = new CronLastRunCheck($this->mockFactory([]));
        $findings = $check->run();

        self::assertCount(1, $findings);
        self::assertSame('IC-041', $findings[0]['id']);
        self::assertSame(Severity::HIGH, $findings[0]['severity']);
        self::assertStringContainsString('No successful', $findings[0]['title']);
    }

    public function testIgnoresNonDefaultGroupJobs(): void
    {
        $rows = [
            // staging_ and index_ prefixes route to non-default groups; even if
            // they ran an hour+ ago the `default` group is still considered
            // never-run within the inspected sample.
            $this->makeRow('staging_synchronize_entities_period', gmdate('Y-m-d H:i:s', time() - 30)),
            $this->makeRow('index_reindex_all_invalid', gmdate('Y-m-d H:i:s', time() - 30)),
        ];

        $check = new CronLastRunCheck($this->mockFactory($rows));
        $findings = $check->run();

        self::assertCount(1, $findings);
        self::assertSame('IC-041', $findings[0]['id']);
        self::assertStringContainsString('No successful', $findings[0]['title']);
    }

    private function makeRow(string $jobCode, string $executedAt): Schedule&MockObject
    {
        $schedule = $this->createMock(Schedule::class);
        $schedule->method('getJobCode')->willReturn($jobCode);
        $schedule->method('getExecutedAt')->willReturn($executedAt);
        $schedule->method('getStatus')->willReturn(Schedule::STATUS_SUCCESS);

        return $schedule;
    }

    /**
     * @param list<Schedule> $rows
     */
    private function mockFactory(array $rows): CronCollectionFactory&MockObject
    {
        $collection = $this->createMock(CronCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('setCurPage')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rows));

        $factory = $this->createMock(CronCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }
}
