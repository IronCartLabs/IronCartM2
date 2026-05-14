<?php

/**
 * IronCart_Scan — IC-013 unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Admin;

use IronCart\Scan\Check\Admin\WeakPasswordIndicatorCheck;
use IronCart\Scan\Check\ScanSession;
use IronCart\Scan\Report\Severity;
use IronCart\Scan\Test\Unit\Check\Admin\Fixture\StubUserCollection;
use Magento\Framework\DataObject;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\User\Model\ResourceModel\User\CollectionFactory;
use PHPUnit\Framework\TestCase;

class WeakPasswordIndicatorCheckTest extends TestCase
{
    private const NOW_TS = 1747000000;

    public function testRecentRotationsEmitNoFinding(): void
    {
        $check = new WeakPasswordIndicatorCheck(
            $this->factory([
                $this->user('alice', daysSinceChange: 10),
                $this->user('bob', daysSinceChange: 90),
            ]),
            $this->clock(),
            new ScanSession(),
        );

        $this->assertSame([], $check->run());
    }

    public function testStalePasswordsAreFlaggedMedium(): void
    {
        $check = new WeakPasswordIndicatorCheck(
            $this->factory([
                $this->user('alice', daysSinceChange: 10),
                $this->user('stale1', daysSinceChange: 200),
                $this->user('stale2', daysSinceChange: 365),
            ]),
            $this->clock(),
            new ScanSession(),
        );

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame('IC-013', $findings[0]['id']);
        $this->assertSame(Severity::MEDIUM, $findings[0]['severity']);
        $this->assertSame(2, $findings[0]['evidence']['stale_count']);
        $this->assertSame(3, $findings[0]['evidence']['total_active']);
        $this->assertSame(180, $findings[0]['evidence']['threshold_days']);
        $this->assertArrayNotHasKey('usernames', $findings[0]['evidence']);
    }

    public function testNeverRotatedFallsBackToCreatedTimestamp(): void
    {
        $user = new DataObject([
            'username' => 'never-rotated',
            'is_active' => 1,
            'password_changed' => null,
            'created' => gmdate('Y-m-d H:i:s', self::NOW_TS - (400 * 86400)),
        ]);

        $check = new WeakPasswordIndicatorCheck(
            $this->factory([$user]),
            $this->clock(),
            new ScanSession(),
        );

        $findings = $check->run();
        $this->assertSame(1, $findings[0]['evidence']['stale_count']);
    }

    public function testEvidenceContainsNoPasswordMaterial(): void
    {
        $session = new ScanSession();
        $session->setIncludeUsernames(true);

        $check = new WeakPasswordIndicatorCheck(
            $this->factory([$this->user('victim', daysSinceChange: 365)]),
            $this->clock(),
            $session,
        );

        $findings = $check->run();
        $evidence = $findings[0]['evidence'];

        // The IronCartM2 v0 invariant: no password material of any kind.
        $serialised = json_encode($evidence);
        $this->assertNotFalse($serialised);
        $this->assertStringNotContainsString('password', strtolower($serialised));
        $this->assertStringNotContainsString('hash', strtolower($serialised));
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

    private function user(string $username, int $daysSinceChange): DataObject
    {
        return new DataObject([
            'username' => $username,
            'is_active' => 1,
            'password_changed' => gmdate('Y-m-d H:i:s', self::NOW_TS - ($daysSinceChange * 86400)),
        ]);
    }
}
