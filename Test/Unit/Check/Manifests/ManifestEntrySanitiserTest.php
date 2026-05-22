<?php

/**
 * IronCart_Scan — unit tests for {@see ManifestEntrySanitiser}.
 *
 * Pins the safety surface shared by the three manifest repositories
 * (IC-070 core file integrity, IC-072 composer-lock integrity, IC-073 Recon
 * baseline). Every behaviour documented on the class doc has a test here so
 * future hardening passes don't drift across the three callers.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Manifests;

use IronCart\Scan\Check\Manifests\ManifestEntrySanitiser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\Manifests\ManifestEntrySanitiser
 */
class ManifestEntrySanitiserTest extends TestCase
{
    public function testKeepsWellFormedEntries(): void
    {
        $result = ManifestEntrySanitiser::sanitise([
            'app/bootstrap.php' => str_repeat('a', 64),
            'pub/index.php' => str_repeat('b', 64),
        ]);

        self::assertSame([
            'app/bootstrap.php' => str_repeat('a', 64),
            'pub/index.php' => str_repeat('b', 64),
        ], $result);
    }

    public function testRejectsForwardSlashTraversal(): void
    {
        $result = ManifestEntrySanitiser::sanitise([
            '../etc/passwd' => str_repeat('a', 64),
            'safe/file.php' => str_repeat('b', 64),
        ]);

        self::assertSame(['safe/file.php' => str_repeat('b', 64)], $result);
    }

    public function testRejectsBackslashTraversal(): void
    {
        // Hardening over the legacy inline loops, which only caught
        // forward-slash traversal. A manifest containing `..\\foo` (e.g.
        // generated on Windows or hand-crafted by an attacker) must NOT
        // reach the value object.
        $result = ManifestEntrySanitiser::sanitise([
            '..\\etc\\passwd' => str_repeat('a', 64),
            'app\\..\\config.php' => str_repeat('b', 64),
            'safe/file.php' => str_repeat('c', 64),
        ]);

        self::assertSame(['safe/file.php' => str_repeat('c', 64)], $result);
    }

    public function testRejectsLeadingForwardSlash(): void
    {
        $result = ManifestEntrySanitiser::sanitise([
            '/etc/shadow' => str_repeat('a', 64),
            'app/legit.php' => str_repeat('b', 64),
        ]);

        self::assertSame(['app/legit.php' => str_repeat('b', 64)], $result);
    }

    public function testRejectsLeadingBackslash(): void
    {
        // Hardening: leading `\\` would let a Windows-host manifest be read
        // as a UNC path or drive-relative path by `is_file()` downstream.
        $result = ManifestEntrySanitiser::sanitise([
            '\\Windows\\System32' => str_repeat('a', 64),
            'app/legit.php' => str_repeat('b', 64),
        ]);

        self::assertSame(['app/legit.php' => str_repeat('b', 64)], $result);
    }

    public function testRejectsNullByte(): void
    {
        $result = ManifestEntrySanitiser::sanitise([
            "embedded\0null.php" => str_repeat('a', 64),
            'app/legit.php' => str_repeat('b', 64),
        ]);

        self::assertSame(['app/legit.php' => str_repeat('b', 64)], $result);
    }

    public function testRejectsNonStringKey(): void
    {
        // JSON `{"0": "..."}` decodes to an int-keyed entry.
        $result = ManifestEntrySanitiser::sanitise([
            0 => str_repeat('a', 64),
            'app/legit.php' => str_repeat('b', 64),
        ]);

        self::assertSame(['app/legit.php' => str_repeat('b', 64)], $result);
    }

    public function testRejectsNonStringValue(): void
    {
        $result = ManifestEntrySanitiser::sanitise([
            'app/bad.php' => 12345,
            'app/legit.php' => str_repeat('b', 64),
        ]);

        self::assertSame(['app/legit.php' => str_repeat('b', 64)], $result);
    }

    public function testRejectsEmptyKey(): void
    {
        $result = ManifestEntrySanitiser::sanitise([
            '' => str_repeat('a', 64),
            'app/legit.php' => str_repeat('b', 64),
        ]);

        self::assertSame(['app/legit.php' => str_repeat('b', 64)], $result);
    }

    public function testRejectsEmptyValue(): void
    {
        $result = ManifestEntrySanitiser::sanitise([
            'app/bad.php' => '',
            'app/legit.php' => str_repeat('b', 64),
        ]);

        self::assertSame(['app/legit.php' => str_repeat('b', 64)], $result);
    }

    public function testNormalisesHashToLowercase(): void
    {
        $result = ManifestEntrySanitiser::sanitise([
            'app/mixed.php' => 'AbCdEf0123456789ABCDEF0123456789AbCdEf01',
        ]);

        self::assertSame(
            ['app/mixed.php' => 'abcdef0123456789abcdef0123456789abcdef01'],
            $result
        );
    }

    public function testReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], ManifestEntrySanitiser::sanitise([]));
    }

    public function testTraversalSegmentMidPathIsRejected(): void
    {
        // Pattern `..` anywhere in the key is rejected — matches the legacy
        // behaviour of `str_contains($relative, '..')`. A literal filename
        // like `..bashrc` is also rejected; this is intentionally
        // conservative — bundled manifests never contain such names.
        $result = ManifestEntrySanitiser::sanitise([
            'app/code/..bashrc' => str_repeat('a', 64),
            'app/code/legit.php' => str_repeat('b', 64),
        ]);

        self::assertSame(['app/code/legit.php' => str_repeat('b', 64)], $result);
    }
}
