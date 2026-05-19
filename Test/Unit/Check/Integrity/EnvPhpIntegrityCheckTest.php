<?php

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Integrity;

use IronCart\Scan\Check\Integrity\EnvPhpIntegrityCheck;
use IronCart\Scan\Report\Severity;
use IronCart\Scan\Test\Unit\Check\Filesystem\FilesystemSandbox;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\Integrity\EnvPhpIntegrityCheck
 */
class EnvPhpIntegrityCheckTest extends TestCase
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
        $check = new EnvPhpIntegrityCheck($this->sandbox->magentoRoot());

        $findings = $check->run();

        self::assertCount(1, $findings);
        self::assertSame(Severity::INFO, $findings[0]['severity']);
        self::assertSame(EnvPhpIntegrityCheck::ID_MODE, $findings[0]['id']);
    }

    public function testNoFindingsForCleanEnvPhp(): void
    {
        $this->sandbox->writeFile('app/etc/env.php', $this->validEnvPhpSource(), 0o640);
        $check = new EnvPhpIntegrityCheck($this->sandbox->magentoRoot());

        $findings = $check->run();

        // Allow ownership / mode noise on platforms whose temp dir is owned
        // by root/www-data in CI; the canonical assertion is that no
        // sensitivity finding (IC-203/IC-204/IC-205) fires for a healthy
        // env.php.
        $sensitivityIds = [
            EnvPhpIntegrityCheck::ID_DEFAULT_CRYPT_KEY,
            EnvPhpIntegrityCheck::ID_EMPTY_DB_PASSWORD,
            EnvPhpIntegrityCheck::ID_SESSION_FILES_NO_PATH,
            EnvPhpIntegrityCheck::ID_SYMLINK,
        ];
        foreach ($findings as $finding) {
            self::assertNotContains($finding['id'], $sensitivityIds, sprintf(
                'Unexpected finding %s on clean env.php',
                $finding['id']
            ));
        }
    }

    public function testHighWhenModeIs644(): void
    {
        $this->sandbox->writeFile('app/etc/env.php', $this->validEnvPhpSource(), 0o644);
        $check = new EnvPhpIntegrityCheck($this->sandbox->magentoRoot());

        $finding = $this->findById($check->run(), EnvPhpIntegrityCheck::ID_MODE);

        self::assertNotNull($finding, 'IC-200 should fire for mode 0644');
        self::assertSame(Severity::HIGH, $finding['severity']);
        self::assertSame('0644', $finding['evidence']['mode']);
    }

    public function testHighWhenModeIs660(): void
    {
        // 0660 = group write -> still violates "0640 or stricter".
        $this->sandbox->writeFile('app/etc/env.php', $this->validEnvPhpSource(), 0o660);
        $check = new EnvPhpIntegrityCheck($this->sandbox->magentoRoot());

        $finding = $this->findById($check->run(), EnvPhpIntegrityCheck::ID_MODE);

        self::assertNotNull($finding, 'IC-200 should fire for mode 0660');
        self::assertSame(Severity::HIGH, $finding['severity']);
    }

    public function testNoModeFindingFor0600(): void
    {
        // 0600 is stricter than 0640 (no group access) -> must not fire.
        $this->sandbox->writeFile('app/etc/env.php', $this->validEnvPhpSource(), 0o600);
        $check = new EnvPhpIntegrityCheck($this->sandbox->magentoRoot());

        $finding = $this->findById($check->run(), EnvPhpIntegrityCheck::ID_MODE);

        self::assertNull($finding, 'IC-200 must not fire for mode 0600');
    }

    public function testFlagsDefaultCryptKey(): void
    {
        $this->sandbox->writeFile(
            'app/etc/env.php',
            $this->envPhpWithCryptKey('0123456789abcdef0123456789abcdef'),
            0o640
        );
        $check = new EnvPhpIntegrityCheck($this->sandbox->magentoRoot());

        $finding = $this->findById($check->run(), EnvPhpIntegrityCheck::ID_DEFAULT_CRYPT_KEY);

        self::assertNotNull($finding);
        self::assertSame(Severity::HIGH, $finding['severity']);
        // Never leak the key bytes.
        self::assertArrayNotHasKey('key', $finding['evidence']);
        self::assertTrue($finding['evidence']['default_match']);
    }

    public function testFlagsAllZeroCryptKey(): void
    {
        $this->sandbox->writeFile(
            'app/etc/env.php',
            $this->envPhpWithCryptKey(str_repeat('0', 64)),
            0o640
        );
        $check = new EnvPhpIntegrityCheck($this->sandbox->magentoRoot());

        $finding = $this->findById($check->run(), EnvPhpIntegrityCheck::ID_DEFAULT_CRYPT_KEY);

        self::assertNotNull($finding);
    }

    public function testDoesNotFlagRotatedCryptKey(): void
    {
        $this->sandbox->writeFile(
            'app/etc/env.php',
            $this->envPhpWithCryptKey('a8b3f0c9e1d2470d8e6f1234abcd5678'),
            0o640
        );
        $check = new EnvPhpIntegrityCheck($this->sandbox->magentoRoot());

        $finding = $this->findById($check->run(), EnvPhpIntegrityCheck::ID_DEFAULT_CRYPT_KEY);

        self::assertNull($finding);
    }

    public function testFlagsEmptyDbPassword(): void
    {
        $source = "<?php return [\n"
            . "    'db' => [\n"
            . "        'connection' => [\n"
            . "            'default' => ['password' => ''],\n"
            . "            'indexer' => ['password' => 'real-pw'],\n"
            . "        ],\n"
            . "    ],\n"
            . "    'crypt' => ['key' => 'a8b3f0c9e1d2470d8e6f1234abcd5678'],\n"
            . "];\n";
        $this->sandbox->writeFile('app/etc/env.php', $source, 0o640);

        $check = new EnvPhpIntegrityCheck($this->sandbox->magentoRoot());

        $findings = array_values(array_filter(
            $check->run(),
            static fn (array $f): bool => $f['id'] === EnvPhpIntegrityCheck::ID_EMPTY_DB_PASSWORD
        ));

        self::assertCount(1, $findings, 'Exactly one IC-204 finding expected (default connection)');
        self::assertSame(Severity::HIGH, $findings[0]['severity']);
        self::assertSame('default', $findings[0]['evidence']['connection']);
        self::assertFalse($findings[0]['evidence']['password_present']);
        // Privacy invariant: the real password from `indexer` must never appear.
        self::assertStringNotContainsString('real-pw', json_encode($findings[0]) ?: '');
    }

    public function testFlagsSessionFilesWithoutPath(): void
    {
        $source = "<?php return [\n"
            . "    'session' => ['save' => 'files'],\n"
            . "    'crypt' => ['key' => 'a8b3f0c9e1d2470d8e6f1234abcd5678'],\n"
            . "];\n";
        $this->sandbox->writeFile('app/etc/env.php', $source, 0o640);

        $check = new EnvPhpIntegrityCheck($this->sandbox->magentoRoot());

        $finding = $this->findById($check->run(), EnvPhpIntegrityCheck::ID_SESSION_FILES_NO_PATH);

        self::assertNotNull($finding);
        self::assertSame(Severity::HIGH, $finding['severity']);
        self::assertFalse($finding['evidence']['save_path_present']);
    }

    public function testDoesNotFlagSessionFilesWithExplicitPath(): void
    {
        $source = "<?php return [\n"
            . "    'session' => ['save' => 'files', 'save_path' => '/var/lib/magento-sessions'],\n"
            . "    'crypt' => ['key' => 'a8b3f0c9e1d2470d8e6f1234abcd5678'],\n"
            . "];\n";
        $this->sandbox->writeFile('app/etc/env.php', $source, 0o640);

        $check = new EnvPhpIntegrityCheck($this->sandbox->magentoRoot());

        $finding = $this->findById($check->run(), EnvPhpIntegrityCheck::ID_SESSION_FILES_NO_PATH);

        self::assertNull($finding);
    }

    public function testDoesNotFlagRedisSessionHandler(): void
    {
        $source = "<?php return [\n"
            . "    'session' => ['save' => 'redis'],\n"
            . "    'crypt' => ['key' => 'a8b3f0c9e1d2470d8e6f1234abcd5678'],\n"
            . "];\n";
        $this->sandbox->writeFile('app/etc/env.php', $source, 0o640);

        $check = new EnvPhpIntegrityCheck($this->sandbox->magentoRoot());

        $finding = $this->findById($check->run(), EnvPhpIntegrityCheck::ID_SESSION_FILES_NO_PATH);

        self::assertNull($finding);
    }

    /**
     * @param list<array{id:string,title:string,severity:string,evidence:mixed,remediation_url:string}> $findings
     * @return array{id:string,title:string,severity:string,evidence:mixed,remediation_url:string}|null
     */
    private function findById(array $findings, string $id): ?array
    {
        foreach ($findings as $finding) {
            if ($finding['id'] === $id) {
                return $finding;
            }
        }

        return null;
    }

    private function validEnvPhpSource(): string
    {
        return "<?php return [\n"
            . "    'crypt' => ['key' => 'a8b3f0c9e1d2470d8e6f1234abcd5678'],\n"
            . "    'db' => ['connection' => ['default' => ['password' => 'real-pw']]],\n"
            . "    'session' => ['save' => 'redis'],\n"
            . "];\n";
    }

    private function envPhpWithCryptKey(string $key): string
    {
        $escaped = addslashes($key);

        return "<?php return [\n"
            . "    'crypt' => ['key' => '{$escaped}'],\n"
            . "    'db' => ['connection' => ['default' => ['password' => 'real-pw']]],\n"
            . "    'session' => ['save' => 'redis'],\n"
            . "];\n";
    }
}
