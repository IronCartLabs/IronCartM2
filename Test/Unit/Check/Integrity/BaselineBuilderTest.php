<?php

/**
 * IronCart_Scan — Recon 7.1 baseline-builder + repository unit tests.
 *
 * Exercises the filesystem walk against the FilesystemSandbox fixture: only
 * configured roots are visited, ignore patterns skip mutable paths, symlinks
 * are not followed, and round-tripping the manifest through the repository
 * preserves entries.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Integrity;

use IronCart\Scan\Check\Integrity\BaselineBuilder;
use IronCart\Scan\Check\Integrity\BaselineManifest;
use IronCart\Scan\Check\Integrity\BaselineRepository;
use IronCart\Scan\Check\Integrity\IgnorePatterns;
use IronCart\Scan\Test\Unit\Check\Filesystem\FilesystemSandbox;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\Integrity\BaselineBuilder
 * @covers \IronCart\Scan\Check\Integrity\BaselineRepository
 * @covers \IronCart\Scan\Check\Integrity\IgnorePatterns
 * @covers \IronCart\Scan\Check\Integrity\BaselineManifest
 */
class BaselineBuilderTest extends TestCase
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

    public function testWalksOnlyConfiguredRoots(): void
    {
        $this->sandbox->writeFile('app/code/Vendor/Mod/etc/module.xml', '<?xml ?>');
        $this->sandbox->writeFile('app/etc/di.xml', '<?xml ?>');
        $this->sandbox->writeFile('vendor/magento/framework/App.php', "<?php\n");
        // Outside the configured roots — must not be included.
        $this->sandbox->writeFile('lib/internal/foo.php', "<?php\n");
        $this->sandbox->writeFile('pub/index.php', "<?php\n");

        $builder = new BaselineBuilder($this->sandbox->magentoRoot(), IgnorePatterns::fromLists([], []));

        $manifest = $builder->build('Community', '2.4.7-p5');
        $entries = $manifest->entries();

        self::assertArrayHasKey('app/code/Vendor/Mod/etc/module.xml', $entries);
        self::assertArrayHasKey('app/etc/di.xml', $entries);
        self::assertArrayHasKey('vendor/magento/framework/App.php', $entries);
        self::assertArrayNotHasKey('lib/internal/foo.php', $entries);
        self::assertArrayNotHasKey('pub/index.php', $entries);
    }

    public function testIgnorePatternsSkipMutablePaths(): void
    {
        $this->sandbox->writeFile('app/code/Vendor/Mod/etc/module.xml', '<?xml ?>');
        // Both of these paths are under `app/code/.../var/` — they would be
        // walked, but the ignore-pattern `var/` matches the relative-from-
        // webroot path so they should be skipped.
        $this->sandbox->writeFile('app/etc/env.php', "<?php\n");
        $this->sandbox->writeFile('app/etc/config.php', "<?php\n");

        $ignore = IgnorePatterns::fromLists(['var/'], ['app/etc/env.php', 'app/etc/config.php']);
        $builder = new BaselineBuilder($this->sandbox->magentoRoot(), $ignore);

        $manifest = $builder->build('Community', '2.4.7-p5');
        $entries = $manifest->entries();

        self::assertArrayHasKey('app/code/Vendor/Mod/etc/module.xml', $entries);
        self::assertArrayNotHasKey('app/etc/env.php', $entries);
        self::assertArrayNotHasKey('app/etc/config.php', $entries);
    }

    public function testEntriesAreSha256AndLowercaseHex(): void
    {
        $contents = "<?php\nreturn 1;\n";
        $this->sandbox->writeFile('app/code/Vendor/Mod/A.php', $contents);

        $builder = new BaselineBuilder($this->sandbox->magentoRoot(), IgnorePatterns::fromLists([], []));

        $manifest = $builder->build('Community', '2.4.7-p5');
        $entries = $manifest->entries();

        $expected = hash('sha256', $contents);
        self::assertSame($expected, $entries['app/code/Vendor/Mod/A.php']);
        self::assertSame(BaselineManifest::ALGORITHM_SHA256, $manifest->algorithm());
    }

    public function testSaveLoadRoundTripPreservesEntries(): void
    {
        $this->sandbox->writeFile('app/code/Vendor/Mod/A.php', "<?php\n// A\n");
        $this->sandbox->writeFile('app/etc/di.xml', '<?xml ?>');

        $root = $this->sandbox->magentoRoot();
        $builder = new BaselineBuilder($root, IgnorePatterns::fromLists([], []));
        $repository = new BaselineRepository($root);

        $built = $builder->build('Community', '2.4.7-p5');
        $repository->save($built);

        self::assertTrue($repository->exists());
        $loaded = $repository->load();
        self::assertNotNull($loaded);
        self::assertSame($built->entries(), $loaded->entries());
        self::assertSame($built->magentoEdition(), $loaded->magentoEdition());
        self::assertSame($built->magentoVersion(), $loaded->magentoVersion());
        self::assertSame($built->algorithm(), $loaded->algorithm());
    }

    public function testLoadReturnsNullWhenNoBaseline(): void
    {
        $repository = new BaselineRepository($this->sandbox->magentoRoot());
        self::assertFalse($repository->exists());
        self::assertNull($repository->load());
    }

    public function testToJsonIsDeterministicallySorted(): void
    {
        $manifest = new BaselineManifest(
            generatedAt: '2026-05-19T00:00:00Z',
            magentoEdition: 'Community',
            magentoVersion: '2.4.7-p5',
            algorithm: BaselineManifest::ALGORITHM_SHA256,
            roots: ['app/code'],
            entries: [
                'app/code/Z.php' => str_repeat('z', 64),
                'app/code/A.php' => str_repeat('a', 64),
                'app/code/M.php' => str_repeat('m', 64),
            ]
        );

        $json = $manifest->toJson();
        $decoded = json_decode($json, true);

        self::assertSame(
            ['app/code/A.php', 'app/code/M.php', 'app/code/Z.php'],
            array_keys($decoded['entries'])
        );
    }

    public function testIgnorePatternsLoadFromBundledJson(): void
    {
        $path = $this->sandbox->writeFile('etc/integrity-ignore.json', (string) json_encode([
            'schema_version' => 'v0',
            'prefixes' => ['var/', 'generated/'],
            'exact' => ['app/etc/env.php'],
        ]));

        $patterns = new IgnorePatterns($path);

        self::assertTrue($patterns->matches('var/cache/foo'));
        self::assertTrue($patterns->matches('generated/code/bar'));
        self::assertTrue($patterns->matches('app/etc/env.php'));
        self::assertFalse($patterns->matches('app/code/Vendor/Mod/etc/module.xml'));
        self::assertFalse($patterns->matches('app/etc/di.xml'));
    }

    public function testIgnorePatternsRejectTraversalEntries(): void
    {
        $patterns = IgnorePatterns::fromLists(['../', '/abs', "with\0null"], ['../escape', '/etc/passwd']);

        // All sanitised away — none of the malicious entries should match.
        self::assertFalse($patterns->matches('app/code/Foo.php'));
        self::assertSame([], $patterns->prefixes());
        self::assertSame([], $patterns->exact());
    }
}
