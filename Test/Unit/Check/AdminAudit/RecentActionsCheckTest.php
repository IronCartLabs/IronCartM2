<?php

/**
 * IronCart_Scan — IC-014 unit tests.
 *
 * Exercises every sub-detector of RecentActionsCheck against PHPUnit doubles
 * that stand in for Magento's admin-user collection, the framework
 * ResourceConnection, and the ScopeConfigInterface admin-config reader. The
 * goal is to assert the v0 PII contract (hashed evidence by default;
 * plaintext only when --include-usernames is set) and the off-hours decision
 * boundary, without booting the Magento application or touching a real DB.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\AdminAudit;

use IronCart\Scan\Check\AdminAudit\RecentActionsCheck;
use IronCart\Scan\Check\ScanSession;
use IronCart\Scan\Report\Severity;
use IronCart\Scan\Test\Unit\Check\Admin\Fixture\StubUserCollection;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\User\Model\ResourceModel\User\CollectionFactory;
use PHPUnit\Framework\TestCase;

class RecentActionsCheckTest extends TestCase
{
    /** Fixed clock — 2026-05-19 12:00:00 UTC. */
    private const NOW_TS = 1779379200;

    public function testEmptyEnvironmentProducesNoFindings(): void
    {
        $check = new RecentActionsCheck(
            $this->userFactory([]),
            $this->resourceConnection([], [], []),
            $this->scopeConfig(null, null),
            $this->clock(),
            new ScanSession(),
        );

        $this->assertSame([], $check->run());
    }

    public function testNewAdminUserInLast24hFiresHighFinding(): void
    {
        $check = new RecentActionsCheck(
            $this->userFactory([
                $this->user('freshly-made', createdHoursAgo: 4, modifiedHoursAgo: 4),
                $this->user('long-standing', createdHoursAgo: 240, modifiedHoursAgo: 240),
            ]),
            $this->resourceConnection([], [], []),
            $this->scopeConfig(null, null),
            $this->clock(),
            new ScanSession(),
        );

        $findings = $this->byId($check->run());

        $this->assertArrayHasKey('IC-014.new-admin', $findings);
        $this->assertSame(Severity::HIGH, $findings['IC-014.new-admin']['severity']);
        $this->assertSame(1, $findings['IC-014.new-admin']['evidence']['count']);
        $this->assertSame(
            [substr(hash('sha256', 'freshly-made'), 0, 16)],
            $findings['IC-014.new-admin']['evidence']['username_hashes']
        );
        $this->assertArrayNotHasKey('usernames', $findings['IC-014.new-admin']['evidence']);
    }

    public function testRecentlyModifiedExistingUserSurfacesAsRoleChangeProxy(): void
    {
        $check = new RecentActionsCheck(
            $this->userFactory([
                // Created 10 days ago, edited 6h ago — proxy for a role change.
                $this->user('admin-edited', createdHoursAgo: 240, modifiedHoursAgo: 6),
                // Fresh user — caught by the new-admin detector, NOT here.
                $this->user('fresh', createdHoursAgo: 2, modifiedHoursAgo: 2),
            ]),
            $this->resourceConnection([], [], []),
            $this->scopeConfig(null, null),
            $this->clock(),
            new ScanSession(),
        );

        $findings = $this->byId($check->run());

        $this->assertArrayHasKey('IC-014.role-change', $findings);
        $this->assertSame(Severity::MEDIUM, $findings['IC-014.role-change']['severity']);
        $this->assertSame(1, $findings['IC-014.role-change']['evidence']['count']);
    }

    public function testPasswordResetInWindowProducesFinding(): void
    {
        $check = new RecentActionsCheck(
            $this->userFactory([]),
            $this->resourceConnection(
                passwordRows: [
                    ['user_id' => 1, 'last_updated' => self::NOW_TS - 3600, 'username' => 'reset-me'],
                ],
                sessionFullRows: [],
                sessionIpRows: [],
            ),
            $this->scopeConfig(null, null),
            $this->clock(),
            new ScanSession(),
        );

        $findings = $this->byId($check->run());

        $this->assertArrayHasKey('IC-014.password-reset', $findings);
        $this->assertSame(Severity::MEDIUM, $findings['IC-014.password-reset']['severity']);
        $this->assertSame(1, $findings['IC-014.password-reset']['evidence']['count']);
        $this->assertSame(
            [substr(hash('sha256', 'reset-me'), 0, 16)],
            $findings['IC-014.password-reset']['evidence']['username_hashes']
        );
    }

    public function testLoginIpPrefixesAreTruncatedToSlash24(): void
    {
        $check = new RecentActionsCheck(
            $this->userFactory([]),
            $this->resourceConnection(
                passwordRows: [],
                sessionFullRows: [],
                sessionIpRows: ['203.0.113.7', '203.0.113.42', '198.51.100.1', 'not-an-ip'],
            ),
            $this->scopeConfig(null, null),
            $this->clock(),
            new ScanSession(),
        );

        $findings = $this->byId($check->run());

        $this->assertArrayHasKey('IC-014.login-ips', $findings);
        $this->assertSame(Severity::INFO, $findings['IC-014.login-ips']['severity']);
        $this->assertSame(
            ['198.51.100.0/24', '203.0.113.0/24', 'not-an-ip'],
            $findings['IC-014.login-ips']['evidence']['ip_prefixes']
        );
    }

    public function testOffHoursLoginSuppressedWhenBusinessHoursNotConfigured(): void
    {
        $check = new RecentActionsCheck(
            $this->userFactory([]),
            $this->resourceConnection(
                passwordRows: [],
                sessionFullRows: [
                    [
                        'user_id' => 1,
                        'updated_at' => gmdate('Y-m-d H:i:s', self::NOW_TS - 3600),
                        'username' => 'midnight-admin',
                    ],
                ],
                sessionIpRows: [],
            ),
            $this->scopeConfig(null, null),
            $this->clock(),
            new ScanSession(),
        );

        $findings = $this->byId($check->run());
        $this->assertArrayNotHasKey('IC-014.off-hours', $findings);
    }

    public function testOffHoursLoginFiresWhenBusinessHoursConfigured(): void
    {
        // Pin "now" at noon UTC; place a session at 02:00 UTC = off-hours
        // against a 9–17 window.
        $loginAtTwoAm = (int) gmmktime(2, 0, 0, 5, 19, 2026);

        $check = new RecentActionsCheck(
            $this->userFactory([]),
            $this->resourceConnection(
                passwordRows: [],
                sessionFullRows: [
                    [
                        'user_id' => 1,
                        'updated_at' => gmdate('Y-m-d H:i:s', $loginAtTwoAm),
                        'username' => 'night-owl',
                    ],
                ],
                sessionIpRows: [],
            ),
            $this->scopeConfig('9', '17'),
            $this->clock(),
            new ScanSession(),
        );

        $findings = $this->byId($check->run());

        $this->assertArrayHasKey('IC-014.off-hours', $findings);
        $this->assertSame(Severity::HIGH, $findings['IC-014.off-hours']['severity']);
        $this->assertSame(1, $findings['IC-014.off-hours']['evidence']['count']);
        $this->assertSame(9, $findings['IC-014.off-hours']['evidence']['business_hours_start']);
        $this->assertSame(17, $findings['IC-014.off-hours']['evidence']['business_hours_end']);
    }

    public function testOvernightBusinessHoursWindowTreatsMiddayAsOffHours(): void
    {
        // Configured window 22 -> 06 (overnight). 12:00 UTC = off-hours.
        $loginAtNoon = (int) gmmktime(12, 0, 0, 5, 19, 2026);

        $check = new RecentActionsCheck(
            $this->userFactory([]),
            $this->resourceConnection(
                passwordRows: [],
                sessionFullRows: [
                    [
                        'user_id' => 1,
                        'updated_at' => gmdate('Y-m-d H:i:s', $loginAtNoon),
                        'username' => 'daywalker',
                    ],
                ],
                sessionIpRows: [],
            ),
            $this->scopeConfig('22', '6'),
            $this->clock(),
            new ScanSession(),
        );

        $findings = $this->byId($check->run());
        $this->assertArrayHasKey('IC-014.off-hours', $findings);
    }

    public function testPlaintextUsernamesIncludedWhenOptedIn(): void
    {
        $session = new ScanSession();
        $session->setIncludeUsernames(true);

        $check = new RecentActionsCheck(
            $this->userFactory([
                $this->user('opt-in-user', createdHoursAgo: 4, modifiedHoursAgo: 4),
            ]),
            $this->resourceConnection([], [], []),
            $this->scopeConfig(null, null),
            $this->clock(),
            $session,
        );

        $findings = $this->byId($check->run());

        $this->assertArrayHasKey('IC-014.new-admin', $findings);
        $this->assertSame(
            ['opt-in-user'],
            $findings['IC-014.new-admin']['evidence']['usernames']
        );
    }

    /**
     * Index findings by id for readable assertions.
     *
     * @param list<array{id:string}> $findings
     * @return array<string, array{id:string,title:string,severity:string,evidence:mixed,remediation_url:string}>
     */
    private function byId(array $findings): array
    {
        $out = [];
        foreach ($findings as $f) {
            $out[$f['id']] = $f;
        }
        return $out;
    }

    /**
     * @param list<DataObject> $rows
     */
    private function userFactory(array $rows): CollectionFactory
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn () => new StubUserCollection($rows)
        );

        return $factory;
    }

    private function user(string $username, int $createdHoursAgo, int $modifiedHoursAgo): DataObject
    {
        return new DataObject([
            'username' => $username,
            'is_active' => 1,
            'created' => gmdate('Y-m-d H:i:s', self::NOW_TS - ($createdHoursAgo * 3600)),
            'modified' => gmdate('Y-m-d H:i:s', self::NOW_TS - ($modifiedHoursAgo * 3600)),
        ]);
    }

    /**
     * @param list<array<string,mixed>> $passwordRows
     * @param list<array<string,mixed>> $sessionFullRows
     * @param list<string>              $sessionIpRows
     */
    private function resourceConnection(
        array $passwordRows,
        array $sessionFullRows,
        array $sessionIpRows,
    ): ResourceConnection {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $callCounters = ['fetchAll' => 0];

        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('select')->willReturn($select);

        // fetchAll is invoked for: password resets (first), then off-hours
        // sessions (second). Return rows in that order.
        $adapter->method('fetchAll')->willReturnCallback(
            static function () use (&$callCounters, $passwordRows, $sessionFullRows) {
                $i = $callCounters['fetchAll']++;
                if ($i === 0) {
                    return $passwordRows;
                }
                if ($i === 1) {
                    return $sessionFullRows;
                }
                return [];
            }
        );

        // fetchCol is only invoked by the IP-prefix detector.
        $adapter->method('fetchCol')->willReturn($sessionIpRows);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $resource->method('getTableName')->willReturnArgument(0);

        return $resource;
    }

    private function scopeConfig(?string $start, ?string $end): ScopeConfigInterface
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(
            static function (string $path) use ($start, $end) {
                return match ($path) {
                    RecentActionsCheck::CONFIG_BUSINESS_HOURS_START => $start,
                    RecentActionsCheck::CONFIG_BUSINESS_HOURS_END => $end,
                    default => null,
                };
            }
        );

        return $config;
    }

    private function clock(): DateTime
    {
        $stub = $this->createMock(DateTime::class);
        $stub->method('gmtTimestamp')->willReturn(self::NOW_TS);

        return $stub;
    }
}
