<?php

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Filesystem;

use IronCart\Scan\Check\Filesystem\CryptKeyCheck;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\Filesystem\CryptKeyCheck
 */
class CryptKeyCheckTest extends TestCase
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
        $findings = (new CryptKeyCheck($this->sandbox->magentoRoot()))->run();

        self::assertCount(1, $findings);
        self::assertSame(Severity::INFO, $findings[0]['severity']);
    }

    public function testNoFindingWhenHealthyKeyPresent(): void
    {
        $this->sandbox->writeFile(
            'app/etc/env.php',
            "<?php\nreturn ['crypt' => ['key' => '" . str_repeat('a', 32) . "']];\n"
        );

        self::assertSame([], (new CryptKeyCheck($this->sandbox->magentoRoot()))->run());
    }

    public function testHighWhenKeyMissing(): void
    {
        $this->sandbox->writeFile('app/etc/env.php', "<?php\nreturn ['crypt' => ['key' => '']];\n");

        $findings = (new CryptKeyCheck($this->sandbox->magentoRoot()))->run();

        self::assertCount(1, $findings);
        self::assertSame(Severity::HIGH, $findings[0]['severity']);
        self::assertFalse($findings[0]['evidence']['present']);
    }

    public function testHighWhenKeyIsPlaceholder(): void
    {
        $this->sandbox->writeFile(
            'app/etc/env.php',
            "<?php\nreturn ['crypt' => ['key' => 'CHANGEME']];\n"
        );

        $findings = (new CryptKeyCheck($this->sandbox->magentoRoot()))->run();

        self::assertCount(1, $findings);
        self::assertSame(Severity::HIGH, $findings[0]['severity']);
        self::assertTrue($findings[0]['evidence']['placeholder_match']);
        // Privacy invariant — the key value must never appear in the evidence payload.
        self::assertArrayNotHasKey('key', $findings[0]['evidence']);
        self::assertStringNotContainsString('CHANGEME', json_encode($findings[0]) ?: '');
    }

    public function testHighWhenLastRotatedKeyIsPlaceholder(): void
    {
        // Magento stores crypt key history as newline-separated; the last entry is active.
        $this->sandbox->writeFile(
            'app/etc/env.php',
            "<?php\nreturn ['crypt' => ['key' => \"" . str_repeat('a', 32) . "\\nCHANGEME\"]];\n"
        );

        $findings = (new CryptKeyCheck($this->sandbox->magentoRoot()))->run();

        self::assertCount(1, $findings);
        self::assertSame(Severity::HIGH, $findings[0]['severity']);
        self::assertSame(2, $findings[0]['evidence']['history_entries']);
    }
}
