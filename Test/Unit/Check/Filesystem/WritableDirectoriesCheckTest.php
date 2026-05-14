<?php

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Filesystem;

use IronCart\Scan\Check\Filesystem\WritableDirectoriesCheck;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\Filesystem\WritableDirectoriesCheck
 */
class WritableDirectoriesCheckTest extends TestCase
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

    public function testInfoWhenDirectoriesMissing(): void
    {
        $findings = (new WritableDirectoriesCheck($this->sandbox->magentoRoot()))->run();

        // pub/media + var both missing → two info findings.
        self::assertCount(2, $findings);
        foreach ($findings as $finding) {
            self::assertSame(Severity::INFO, $finding['severity']);
        }
    }

    public function testNoFindingWhenDirectoriesAreNotWorldWritable(): void
    {
        $this->sandbox->makeDir('pub/media', 0o775);
        $this->sandbox->makeDir('var', 0o775);

        self::assertSame([], (new WritableDirectoriesCheck($this->sandbox->magentoRoot()))->run());
    }

    public function testMediumWhenPubMediaIsWorldWritable(): void
    {
        $this->sandbox->makeDir('pub/media', 0o777);
        $this->sandbox->makeDir('var', 0o775);

        $findings = (new WritableDirectoriesCheck($this->sandbox->magentoRoot()))->run();

        self::assertCount(1, $findings);
        self::assertSame(Severity::MEDIUM, $findings[0]['severity']);
        self::assertStringContainsString('pub/media', $findings[0]['title']);
    }

    public function testMediumWhenVarIsWorldWritable(): void
    {
        $this->sandbox->makeDir('pub/media', 0o775);
        $this->sandbox->makeDir('var', 0o777);

        $findings = (new WritableDirectoriesCheck($this->sandbox->magentoRoot()))->run();

        self::assertCount(1, $findings);
        self::assertSame(Severity::MEDIUM, $findings[0]['severity']);
        self::assertStringContainsString('var', $findings[0]['title']);
    }
}
