<?php

/**
 * IronCart_Scan — unit tests for {@see UpgradeNagEmitter}.
 *
 * Pins the AC for IronCartLabs/IronCartM2#104:
 *
 *   1. Empty blob → `shouldEmit()` true, `cliMessage()` returns the
 *      canonical Pro-upgrade line, `pushAdminNotice()` invokes the
 *      Magento notifier exactly once.
 *
 *   2. Non-empty blob → `shouldEmit()` false, `cliMessage()` returns
 *      null, `pushAdminNotice()` never touches the notifier. The
 *      suppression is intentionally agnostic of verification result —
 *      the dashboard surfaces invalid-license errors separately.
 *
 *   3. NotifierInterface failures are swallowed so a broken adminhtml
 *      surface can never turn a green upload into a red one.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\License;

use IronCart\Scan\Check\License\LicenseConfig;
use IronCart\Scan\Check\License\UpgradeNagEmitter;
use Magento\Framework\Notification\NotifierInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \IronCart\Scan\Check\License\UpgradeNagEmitter
 */
class UpgradeNagEmitterTest extends TestCase
{
    public function testEmptyBlobFiresCliNagAndAdminNotice(): void
    {
        $licenseConfig = $this->createMock(LicenseConfig::class);
        $licenseConfig->method('blob')->willReturn('');

        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects(self::once())
            ->method('addNotice')
            ->with(
                self::equalTo(UpgradeNagEmitter::NOTICE_TITLE),
                self::equalTo(UpgradeNagEmitter::NOTICE_DESCRIPTION),
                self::equalTo(UpgradeNagEmitter::NOTICE_URL)
            );

        $emitter = new UpgradeNagEmitter($licenseConfig, $notifier);

        self::assertTrue($emitter->shouldEmit());
        self::assertSame(UpgradeNagEmitter::CLI_MESSAGE, $emitter->cliMessage());
        self::assertStringContainsString('https://ironcart.dev/pro', UpgradeNagEmitter::CLI_MESSAGE);
        self::assertTrue($emitter->pushAdminNotice());
    }

    public function testConfiguredBlobSuppressesNagOnBothSurfaces(): void
    {
        // Any non-empty blob suppresses, even one that would fail
        // verification — the dashboard surfaces invalid-license errors
        // separately so we don't double-nag.
        $licenseConfig = $this->createMock(LicenseConfig::class);
        $licenseConfig->method('blob')->willReturn('eyJhY2NvdW50SWQiOiJhYmMi.somebadsig');

        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects(self::never())->method('addNotice');

        $emitter = new UpgradeNagEmitter($licenseConfig, $notifier);

        self::assertFalse($emitter->shouldEmit());
        self::assertNull($emitter->cliMessage());
        self::assertFalse($emitter->pushAdminNotice());
    }

    public function testValidLicenseBlobAlsoSuppressesNag(): void
    {
        // Mirror of the "configured but invalid" case for the happy
        // path: a paid customer with a clean license never sees the
        // nag, on either surface.
        $licenseConfig = $this->createMock(LicenseConfig::class);
        $licenseConfig->method('blob')->willReturn('eyJhY2NvdW50SWQiOiJwcm8ifQ.realsig');

        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects(self::never())->method('addNotice');

        $emitter = new UpgradeNagEmitter($licenseConfig, $notifier);

        self::assertFalse($emitter->shouldEmit());
        self::assertNull($emitter->cliMessage());
        self::assertFalse($emitter->pushAdminNotice());
    }

    public function testNotifierFailureIsSwallowedSoUploadStaysGreen(): void
    {
        // A broken adminhtml notifier (DB write failure, deserialisation
        // bug, ...) must not turn an otherwise-successful upload into a
        // failure. The CLI line still surfaces independently.
        $licenseConfig = $this->createMock(LicenseConfig::class);
        $licenseConfig->method('blob')->willReturn('');

        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->method('addNotice')->willThrowException(new RuntimeException('notifier exploded'));

        $emitter = new UpgradeNagEmitter($licenseConfig, $notifier);

        // No exception escapes; pushAdminNotice reports "did not surface".
        self::assertFalse($emitter->pushAdminNotice());
        // CLI surface still works.
        self::assertSame(UpgradeNagEmitter::CLI_MESSAGE, $emitter->cliMessage());
    }

    public function testCliMessageMatchesAcceptanceCriteriaWordingExactly(): void
    {
        // The AC on #104 pins this exact sentence. If a future refactor
        // wants to reword it, the AC must be updated FIRST — this test
        // is the canary that catches accidental drift.
        self::assertSame(
            'Upgrade to Pro for unlimited hosted reports, continuous monitoring, '
            . 'and notifications: https://ironcart.dev/pro',
            UpgradeNagEmitter::CLI_MESSAGE
        );
    }
}
