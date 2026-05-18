<?php

/**
 * IronCart_Scan — DeprecationRegistry unit tests.
 *
 * Pins the v5 announce-before-remove taxonomy so an accidental removal,
 * relaxation, or addition shows up as a failing test instead of a silent
 * change in operator-visible CLI/UI surfaces.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Report;

use IronCart\Scan\Check\DeprecationRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\DeprecationRegistry
 */
class DeprecationRegistryTest extends TestCase
{
    public function testAllExpectedIdsAreDeprecated(): void
    {
        $registry = new DeprecationRegistry();
        foreach (['IC-060', 'IC-061', 'IC-070', 'IC-071', 'IC-072', 'IC-073'] as $id) {
            self::assertTrue(
                $registry->isDeprecated($id),
                sprintf('Issue #83 lists %s in the v5 deprecation set', $id)
            );
        }
    }

    public function testNonDeprecatedIdsAreNotMatched(): void
    {
        $registry = new DeprecationRegistry();
        foreach (['IC-001', 'IC-010', 'IC-050', 'IC-080', 'IC-090', ''] as $id) {
            self::assertFalse(
                $registry->isDeprecated($id),
                sprintf('Untouched check %s must NOT be flagged deprecated', $id)
            );
        }
    }

    public function testOnlyPrimaryRegistryKeysAreFilteredFromRunAll(): void
    {
        // The CheckRegistry only ever sees the di.xml-registered key
        // (e.g. IC-060), never the fallback id (IC-061) that the same
        // check class emits internally. We must NOT match IC-061 here
        // — otherwise the registry would skip a check that does NOT
        // appear in di.xml at all, which is incoherent.
        $registry = new DeprecationRegistry();
        self::assertTrue($registry->isDeprecatedRegistryKey('IC-060'));
        self::assertTrue($registry->isDeprecatedRegistryKey('IC-070'));
        self::assertTrue($registry->isDeprecatedRegistryKey('IC-072'));
        self::assertFalse($registry->isDeprecatedRegistryKey('IC-061'));
        self::assertFalse($registry->isDeprecatedRegistryKey('IC-071'));
        self::assertFalse($registry->isDeprecatedRegistryKey('IC-073'));
        self::assertFalse($registry->isDeprecatedRegistryKey('IC-001'));
    }

    public function testMetadataShapeIsFrozen(): void
    {
        $registry = new DeprecationRegistry();
        $meta = $registry->metadataFor('IC-060');

        self::assertIsArray($meta);
        self::assertSame(DeprecationRegistry::DEPRECATED_IN, $meta['deprecated_in']);
        self::assertSame(DeprecationRegistry::REMOVAL_IN, $meta['removal_in']);
        self::assertSame(DeprecationRegistry::REPLACEMENT_PACKAGE, $meta['replacement']);
        self::assertSame(DeprecationRegistry::MIGRATION_URL, $meta['migration_url']);
    }

    public function testV1MajorIsTheRemovalTarget(): void
    {
        // Pin the major-version contract: deprecation lands in v1.x and
        // removal lands at the v2.x boundary. Changing either of these
        // is a v5-epic decision, not a refactor.
        self::assertStringStartsWith('1.', DeprecationRegistry::DEPRECATED_IN);
        self::assertStringStartsWith('2.', DeprecationRegistry::REMOVAL_IN);
    }

    public function testReplacementIsTheProPackage(): void
    {
        self::assertSame(
            'ironcartlabs/magento-scan-pro',
            DeprecationRegistry::REPLACEMENT_PACKAGE
        );
    }

    public function testMigrationUrlIsHttpsIroncartDev(): void
    {
        self::assertStringStartsWith(
            'https://ironcart.dev/',
            DeprecationRegistry::MIGRATION_URL,
            'Migration URL must live on ironcart.dev'
        );
    }

    public function testNoticeCopyShapeIsStable(): void
    {
        $registry = new DeprecationRegistry();
        $notice = $registry->notice('IC-060');

        self::assertStringStartsWith('[DEPRECATED]', $notice);
        self::assertStringContainsString('IC-060', $notice);
        self::assertStringContainsString('ironcartlabs/magento-scan-pro', $notice);
        self::assertStringContainsString(DeprecationRegistry::REMOVAL_IN, $notice);
        self::assertStringContainsString(DeprecationRegistry::MIGRATION_URL, $notice);
        self::assertStringContainsString('include-deprecated=false', $notice);
    }

    public function testMetadataIsNullForUnknownId(): void
    {
        $registry = new DeprecationRegistry();
        self::assertNull($registry->metadataFor('IC-not-a-real-id'));
        self::assertNull($registry->metadataFor(''));
    }

    public function testDeprecatedRegistryKeysAreStable(): void
    {
        // Order matters — the stderr notice loop iterates in this order
        // so operators see a deterministic sequence on every run. Adding
        // a new deprecated check is a deliberate v5 / v6 / v7 decision,
        // not a refactor.
        self::assertSame(
            ['IC-060', 'IC-070', 'IC-072'],
            (new DeprecationRegistry())->deprecatedRegistryKeys()
        );
    }
}
