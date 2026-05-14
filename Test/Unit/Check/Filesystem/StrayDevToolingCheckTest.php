<?php

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Filesystem;

use IronCart\Scan\Check\Filesystem\StrayDevToolingCheck;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\Filesystem\StrayDevToolingCheck
 */
class StrayDevToolingCheckTest extends TestCase
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

    public function testCleanPubReturnsNoFindings(): void
    {
        $this->sandbox->makeDir('pub');

        self::assertSame([], (new StrayDevToolingCheck($this->sandbox->magentoRoot()))->run());
    }

    public function testProfilerFileFlaggedHigh(): void
    {
        $this->sandbox->writeFile('pub/profiler.php', '<?php');

        $findings = (new StrayDevToolingCheck($this->sandbox->magentoRoot()))->run();

        self::assertCount(1, $findings);
        self::assertSame(Severity::HIGH, $findings[0]['severity']);
        self::assertSame(StrayDevToolingCheck::ID, $findings[0]['id']);
        self::assertSame('file', $findings[0]['evidence']['kind']);
    }

    public function testGitDirectoryFlaggedHigh(): void
    {
        $this->sandbox->makeDir('pub/.git');

        $findings = (new StrayDevToolingCheck($this->sandbox->magentoRoot()))->run();

        self::assertCount(1, $findings);
        self::assertSame(Severity::HIGH, $findings[0]['severity']);
        self::assertSame('dir', $findings[0]['evidence']['kind']);
    }

    public function testMultipleStrayArtefactsAllReported(): void
    {
        $this->sandbox->writeFile('pub/profiler.php', '<?php');
        $this->sandbox->writeFile('pub/composer.json', '{}');
        $this->sandbox->makeDir('pub/.git');

        $findings = (new StrayDevToolingCheck($this->sandbox->magentoRoot()))->run();

        self::assertCount(3, $findings);
    }
}
