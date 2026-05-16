<?php

/**
 * IronCart_Scan — IC-070 manifest loader tests.
 *
 * Focused on the safety surface of {@see ManifestRepository}: schema-version
 * enforcement, edition gating, JSON parse failure modes, and rejection of
 * manifest entries that would let a malformed file make the check read
 * outside the Magento webroot.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\FileIntegrity;

use IronCart\Scan\Check\FileIntegrity\ManifestRepository;
use IronCart\Scan\Test\Unit\Check\Filesystem\FilesystemSandbox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \IronCart\Scan\Check\FileIntegrity\ManifestRepository
 * @covers \IronCart\Scan\Check\FileIntegrity\Manifest
 */
class ManifestRepositoryTest extends TestCase
{
    private FilesystemSandbox $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = new FilesystemSandbox();
    }

    protected function tearDown(): void
    {
        $this->sandbox->cleanup();
    }

    public function testReturnsNullForUnsupportedEdition(): void
    {
        $repository = new ManifestRepository($this->sandbox->root());

        self::assertNull($repository->find('Enterprise', '2.4.7-p5'));
        self::assertNull($repository->find('mageos', '2.4.7-p5'));
    }

    public function testReturnsNullForMissingManifest(): void
    {
        $repository = new ManifestRepository($this->sandbox->root());

        self::assertNull($repository->find('Community', '9.9.9-future'));
    }

    public function testCaseInsensitiveEditionLookup(): void
    {
        $this->writeManifest('community', '2.4.7-p5', ['app/bootstrap.php' => str_repeat('a', 64)]);
        $repository = new ManifestRepository($this->sandbox->root());

        // Magento reports getEdition() as "Community" (Capitalised) — must match.
        $manifest = $repository->find('Community', '2.4.7-p5');
        self::assertNotNull($manifest);
        self::assertSame('community', $manifest->edition());
        self::assertSame('2.4.7-p5', $manifest->version());
        self::assertSame(1, $manifest->count());
    }

    public function testRejectsWrongSchemaVersion(): void
    {
        $payload = [
            'schema_version' => 'v999',
            'edition' => 'community',
            'version' => '2.4.7-p5',
            'algorithm' => 'sha256',
            'entries' => ['app/bootstrap.php' => str_repeat('a', 64)],
        ];
        $this->sandbox->writeFile('magento-core-community-2.4.7-p5.json', json_encode($payload));

        $repository = new ManifestRepository($this->sandbox->root());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/schema_version=v999/');
        $repository->find('Community', '2.4.7-p5');
    }

    public function testRejectsMalformedJson(): void
    {
        $this->sandbox->writeFile('magento-core-community-2.4.7-p5.json', '{not json');
        $repository = new ManifestRepository($this->sandbox->root());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');
        $repository->find('Community', '2.4.7-p5');
    }

    public function testStripsTraversalEntries(): void
    {
        $this->writeManifest('community', '2.4.7-p5', [
            'app/bootstrap.php' => str_repeat('a', 64),
            '../etc/passwd' => str_repeat('b', 64),
            '/etc/shadow' => str_repeat('c', 64),
            "embedded\0null" => str_repeat('d', 64),
            'legit/relative.php' => str_repeat('e', 64),
        ]);
        $repository = new ManifestRepository($this->sandbox->root());

        $manifest = $repository->find('Community', '2.4.7-p5');
        self::assertNotNull($manifest);

        $entries = iterator_to_array($this->iterableToGenerator($manifest->entries()));
        self::assertArrayHasKey('app/bootstrap.php', $entries);
        self::assertArrayHasKey('legit/relative.php', $entries);
        self::assertArrayNotHasKey('../etc/passwd', $entries);
        self::assertArrayNotHasKey('/etc/shadow', $entries);
        self::assertCount(2, $entries);
    }

    public function testCachesPerEditionVersionPair(): void
    {
        $this->writeManifest('community', '2.4.7-p5', ['app/bootstrap.php' => str_repeat('a', 64)]);
        $repository = new ManifestRepository($this->sandbox->root());

        $first = $repository->find('Community', '2.4.7-p5');
        // Delete the file from disk; a second call must still return the
        // cached instance (proves the second call did not re-read the file).
        unlink($this->sandbox->root() . '/magento-core-community-2.4.7-p5.json');
        $second = $repository->find('Community', '2.4.7-p5');

        self::assertSame($first, $second);
    }

    /**
     * @param array<string,string> $entries
     */
    private function writeManifest(string $edition, string $version, array $entries): void
    {
        $payload = [
            'schema_version' => ManifestRepository::SCHEMA_VERSION,
            'edition' => $edition,
            'version' => $version,
            'source' => 'https://github.com/magento/magento2.git',
            'source_ref' => $version,
            'generated_at' => '2026-05-16',
            'algorithm' => 'sha256',
            'entries' => $entries,
        ];
        $this->sandbox->writeFile(
            sprintf('magento-core-%s-%s.json', $edition, $version),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Wrap an iterable so {@see iterator_to_array} works regardless of
     * whether the manifest exposes a generator or an array.
     *
     * @param iterable<string,string> $iterable
     * @return \Generator<string,string>
     */
    private function iterableToGenerator(iterable $iterable): \Generator
    {
        foreach ($iterable as $key => $value) {
            yield $key => $value;
        }
    }
}
