<?php

/**
 * IronCart_Scan — IC-012 unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Admin;

use IronCart\Scan\Check\Admin\TwoFactorCoverageCheck;
use IronCart\Scan\Check\ScanSession;
use IronCart\Scan\Report\Severity;
use IronCart\Scan\Test\Unit\Check\Admin\Fixture\StubUserCollection;
use Magento\Framework\DataObject;
use Magento\User\Model\ResourceModel\User\CollectionFactory;
use PHPUnit\Framework\TestCase;

class TwoFactorCoverageCheckTest extends TestCase
{
    public function testFullCoverageEmitsNoFinding(): void
    {
        $check = new TwoFactorCoverageCheck(
            $this->factory([
                $this->user('alice', enrolled: true, roleId: 1),
                $this->user('bob', enrolled: true, roleId: 2),
            ]),
            new ScanSession(),
        );

        $this->assertSame([], $check->run());
    }

    public function testUnenrolledPrivilegedUserIsCritical(): void
    {
        $check = new TwoFactorCoverageCheck(
            $this->factory([
                $this->user('alice', enrolled: true, roleId: 1),
                $this->user('bob', enrolled: false, roleId: 1),
            ]),
            new ScanSession(),
        );

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame('IC-012', $findings[0]['id']);
        $this->assertSame(Severity::CRITICAL, $findings[0]['severity']);
        $this->assertSame(2, $findings[0]['evidence']['total_active']);
        $this->assertSame(1, $findings[0]['evidence']['enrolled']);
        $this->assertSame(1, $findings[0]['evidence']['unenrolled']);
        $this->assertSame(50, $findings[0]['evidence']['coverage_pct']);
        $this->assertCount(1, $findings[0]['evidence']['unenrolled_privileged']);
        $this->assertSame([], $findings[0]['evidence']['unenrolled_unprivileged']);
    }

    public function testUnprivilegedOnlyGapIsHigh(): void
    {
        $check = new TwoFactorCoverageCheck(
            $this->factory([
                $this->user('alice', enrolled: true, roleId: 1),
                $this->user('orphan', enrolled: false, roleId: null),
            ]),
            new ScanSession(),
        );

        $findings = $check->run();

        $this->assertSame(Severity::HIGH, $findings[0]['severity']);
        $this->assertSame([], $findings[0]['evidence']['unenrolled_privileged']);
        $this->assertCount(1, $findings[0]['evidence']['unenrolled_unprivileged']);
    }

    public function testUsernamesOmittedByDefault(): void
    {
        $check = new TwoFactorCoverageCheck(
            $this->factory([
                $this->user('bob', enrolled: false, roleId: 1),
            ]),
            new ScanSession(),
        );

        $findings = $check->run();

        foreach ($findings[0]['evidence']['unenrolled_privileged'] as $record) {
            $this->assertArrayNotHasKey('username', $record);
        }
    }

    public function testUsernamesIncludedWhenOptedIn(): void
    {
        $session = new ScanSession();
        $session->setIncludeUsernames(true);

        $check = new TwoFactorCoverageCheck(
            $this->factory([
                $this->user('bob', enrolled: false, roleId: 1),
            ]),
            $session,
        );

        $findings = $check->run();

        $this->assertSame('bob', $findings[0]['evidence']['unenrolled_privileged'][0]['username']);
    }

    public function testLegacyProviderColumnCountsAsEnrolled(): void
    {
        $legacy = new DataObject([
            'user_id' => 42,
            'username' => 'legacy',
            'is_active' => 1,
            'role_id' => 1,
            'twofactorauth_provider_data' => 'authy:configured',
        ]);

        $check = new TwoFactorCoverageCheck(
            $this->factory([$legacy]),
            new ScanSession(),
        );

        $this->assertSame([], $check->run());
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

    private function user(string $username, bool $enrolled, ?int $roleId): DataObject
    {
        return new DataObject([
            'user_id' => abs(crc32($username)) % 10000,
            'username' => $username,
            'is_active' => 1,
            'role_id' => $roleId,
            'tfa_providers_codes' => $enrolled ? '["google"]' : null,
        ]);
    }
}
