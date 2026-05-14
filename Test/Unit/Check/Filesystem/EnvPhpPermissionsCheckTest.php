<?php

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Filesystem;

use IronCart\Scan\Check\Filesystem\EnvPhpPermissionsCheck;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\Filesystem\EnvPhpPermissionsCheck
 */
class EnvPhpPermissionsCheckTest extends TestCase
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

    public function testEmitsInfoWhenEnvPhpMissing(): void
    {
        $check = new EnvPhpPermissionsCheck($this->sandbox->magentoRoot());

        $findings = $check->run();

        self::assertCount(1, $findings);
        self::assertSame(Severity::INFO, $findings[0]['severity']);
        self::assertSame(EnvPhpPermissionsCheck::ID, $findings[0]['id']);
    }

    public function testNoFindingWhenModeIs640(): void
    {
        $this->sandbox->writeFile('app/etc/env.php', '<?php return [];', 0o640);
        $check = new EnvPhpPermissionsCheck($this->sandbox->magentoRoot());

        self::assertSame([], $check->run());
    }

    public function testHighWhenModeIs644(): void
    {
        $this->sandbox->writeFile('app/etc/env.php', '<?php return [];', 0o644);
        $check = new EnvPhpPermissionsCheck($this->sandbox->magentoRoot());

        $findings = $check->run();

        self::assertCount(1, $findings);
        self::assertSame(Severity::HIGH, $findings[0]['severity']);
        self::assertSame(EnvPhpPermissionsCheck::ID, $findings[0]['id']);
        self::assertSame('0644', $findings[0]['evidence']['mode']);
    }

    public function testHighWhenModeIs666(): void
    {
        $this->sandbox->writeFile('app/etc/env.php', '<?php return [];', 0o666);
        $check = new EnvPhpPermissionsCheck($this->sandbox->magentoRoot());

        $findings = $check->run();

        self::assertCount(1, $findings);
        self::assertSame(Severity::HIGH, $findings[0]['severity']);
    }
}
