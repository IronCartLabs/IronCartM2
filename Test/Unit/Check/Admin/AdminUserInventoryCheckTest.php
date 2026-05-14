<?php

/**
 * IronCart_Scan — IC-011 unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Admin;

use IronCart\Scan\Check\Admin\AdminUserInventoryCheck;
use IronCart\Scan\Check\ScanSession;
use IronCart\Scan\Report\Severity;
use IronCart\Scan\Test\Unit\Check\Admin\Fixture\StubUserCollection;
use Magento\Framework\DataObject;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\User\Model\ResourceModel\User\CollectionFactory;
use PHPUnit\Framework\TestCase;

class AdminUserInventoryCheckTest extends TestCase
{
    private const NOW_TS = 1747000000; // fixed clock for determinism

    public function testFreshLoginsProduceNoFindings(): void
    {
        $check = new AdminUserInventoryCheck(
            $this->factory([
                $this->user('alice', daysSinceLogin: 5),
                $this->user('bob', daysSinceLogin: 30),
            ]),
            $this->clock(),
            new ScanSession(),
        );

        $this->assertSame([], $check->run());
    }

    public function testStaleLoginsAreFlaggedMedium(): void
    {
        $check = new AdminUserInventoryCheck(
            $this->factory([
                $this->user('alice', daysSinceLogin: 5),
                $this->user('stale1', daysSinceLogin: 120),
                $this->user('stale2', daysSinceLogin: 400),
            ]),
            $this->clock(),
            new ScanSession(),
        );

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame('IC-011', $findings[0]['id']);
        $this->assertSame(Severity::MEDIUM, $findings[0]['severity']);
        $this->assertSame(2, $findings[0]['evidence']['stale_count']);
        $this->assertSame(3, $findings[0]['evidence']['total_active']);
        $this->assertSame(90, $findings[0]['evidence']['threshold_days']);
        $this->assertArrayNotHasKey('usernames', $findings[0]['evidence']);
    }

    public function testNeverLoggedInUserCountsAsStale(): void
    {
        $check = new AdminUserInventoryCheck(
            $this->factory([
                $this->user('alice', daysSinceLogin: null),
            ]),
            $this->clock(),
            new ScanSession(),
        );

        $findings = $check->run();
        $this->assertSame(1, $findings[0]['evidence']['stale_count']);
    }

    public function testUsernamesOnlyIncludedWhenOptedIn(): void
    {
        $session = new ScanSession();
        $session->setIncludeUsernames(true);

        $check = new AdminUserInventoryCheck(
            $this->factory([$this->user('stale-admin', daysSinceLogin: 200)]),
            $this->clock(),
            $session,
        );

        $findings = $check->run();

        $this->assertSame(['stale-admin'], $findings[0]['evidence']['usernames']);
    }

    /**
     * @param list<DataObject> $rows
     */
    private function factory(array $rows): CollectionFactory
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn(new StubUserCollection($rows));

        return $factory;
    }

    private function clock(): DateTime
    {
        $stub = $this->createMock(DateTime::class);
        $stub->method('gmtTimestamp')->willReturn(self::NOW_TS);

        return $stub;
    }

    private function user(string $username, ?int $daysSinceLogin): DataObject
    {
        $logdate = $daysSinceLogin === null
            ? null
            : gmdate('Y-m-d H:i:s', self::NOW_TS - ($daysSinceLogin * 86400));

        return new DataObject([
            'username' => $username,
            'is_active' => 1,
            'logdate' => $logdate,
        ]);
    }
}
