<?php

/**
 * IronCart_Scan — drain-lock-name shape test.
 *
 * Pins the {@see \IronCart\Scan\Cron\DrainScanConsumer::LOCK_NAME} and
 * {@see \IronCart\Scan\Model\ScanRunConsumer::LOCK_NAME} constants to
 * the same string value via raw source-file inspection — no Magento
 * types are loaded, so this test runs under the Magento-free unit cell
 * declared in `.github/workflows/ci.yml` (`Test/Unit/Report` testsuite).
 *
 * The two constants are duplicated as literals on purpose (see the
 * docblock on ScanRunConsumer::LOCK_NAME) so `Model\` stays independent
 * of `Cron\`. The cost of that decoupling is exactly one regression
 * vector: if a future refactor renames one without the other, the race
 * fix from IronCartLabs/IronCartM2#155 silently re-opens because the
 * cron-tick lock and the in-handler lock would land on different
 * Magento lock-provider rows. This test fails LOUDLY on that drift.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Report;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class ConsumerLockNameShapeTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../../..';

    /**
     * The literal that BOTH classes must declare as their `LOCK_NAME`.
     * Bumping this value is a coordinated change across cron + consumer
     * + every operator-facing reference (README + admin notice text).
     */
    private const EXPECTED_LOCK_NAME = 'ironcart_scan_consumer_drain';

    public function testDrainScanConsumerDeclaresExpectedLockName(): void
    {
        $value = $this->extractLockNameLiteral(
            self::REPO_ROOT . '/Cron/DrainScanConsumer.php'
        );
        self::assertSame(
            self::EXPECTED_LOCK_NAME,
            $value,
            'DrainScanConsumer::LOCK_NAME drifted from the expected literal'
        );
    }

    public function testScanRunConsumerDeclaresExpectedLockName(): void
    {
        $value = $this->extractLockNameLiteral(
            self::REPO_ROOT . '/Model/ScanRunConsumer.php'
        );
        self::assertSame(
            self::EXPECTED_LOCK_NAME,
            $value,
            'ScanRunConsumer::LOCK_NAME drifted from the expected literal'
        );
    }

    /**
     * Find the first `public const LOCK_NAME = '...'` declaration in the
     * file and return the quoted value. Throws AssertionFailedError if
     * the declaration is missing, so a rename of the constant itself
     * also fails this test instead of returning a stale match.
     */
    private function extractLockNameLiteral(string $path): string
    {
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        // Match either single- or double-quoted string. Spaces around
        // `=` are optional. Anchored to `public const LOCK_NAME` so we
        // don't accidentally match a docblock reference.
        $matched = preg_match(
            '/public\s+const\s+LOCK_NAME\s*=\s*[\'"]([^\'"]+)[\'"]/',
            $src,
            $m
        );
        self::assertSame(
            1,
            $matched,
            sprintf('Could not locate `public const LOCK_NAME = ...` in %s', $path)
        );
        return $m[1];
    }
}
