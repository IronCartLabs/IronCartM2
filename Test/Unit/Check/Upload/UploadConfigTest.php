<?php

/**
 * IronCart_Scan — unit tests for {@see UploadConfig} multi-store
 * resolution order (#123).
 *
 * Covers the three layers the upload runner branches on:
 *
 *   1. CLI override via {@see ScanSession::uploadTokenOverride()}
 *      ({@see UploadConfigTest::testCliOverrideBeatsEnvAndConfig()})
 *   2. Env var `IRONCART_SCAN_UPLOAD_TOKEN`
 *      ({@see UploadConfigTest::testEnvVarOverridesConfigData()})
 *   3. `core_config_data` (encrypted, decrypted via Magento encryptor)
 *      ({@see UploadConfigTest::testConfigDataResolvedWhenEnvAndCliBothMissing()})
 *
 * Plus the enable-flag truthiness coverage for the env-var path:
 *
 *   - `IRONCART_SCAN_UPLOAD_ENABLED=1|true|yes|on` → enabled
 *   - `IRONCART_SCAN_UPLOAD_ENABLED=0|false|no|off` → disabled
 *   - unset / unknown value → fall through to admin `isSetFlag()`
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Upload;

use IronCart\Scan\Check\ScanSession;
use IronCart\Scan\Check\Upload\UploadConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\Upload\UploadConfig
 */
class UploadConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        // Belt-and-braces — never leak env state to sibling tests.
        putenv(UploadConfig::ENV_TOKEN);
        putenv(UploadConfig::ENV_ENABLED);
    }

    public function testCliOverrideBeatsEnvAndConfig(): void
    {
        $session = new ScanSession();
        $session->setUploadTokenOverride('cli-token');
        putenv(UploadConfig::ENV_TOKEN . '=env-token');

        $config = $this->makeConfig(
            tokenInConfig: 'config-token',
            session: $session
        );

        self::assertSame('cli-token', $config->token());
    }

    public function testEnvVarOverridesConfigData(): void
    {
        putenv(UploadConfig::ENV_TOKEN . '=env-token');

        $config = $this->makeConfig(
            tokenInConfig: 'config-token',
            session: null
        );

        self::assertSame('env-token', $config->token());
    }

    public function testConfigDataResolvedWhenEnvAndCliBothMissing(): void
    {
        $config = $this->makeConfig(
            tokenInConfig: 'config-token',
            session: new ScanSession() // no override
        );

        self::assertSame('config-token', $config->token());
    }

    public function testEmptyEnvVarFallsThroughToConfigData(): void
    {
        // Exported-but-empty must not shadow a real config_data token.
        putenv(UploadConfig::ENV_TOKEN . '=');

        $config = $this->makeConfig(
            tokenInConfig: 'config-token',
            session: null
        );

        self::assertSame('config-token', $config->token());
    }

    public function testEmptyCliOverrideTreatedAsUnset(): void
    {
        // setUploadTokenOverride('') is documented to clear the override
        // — the CLI passes `getOption(...)` results verbatim and a
        // missing flag surfaces as null/empty.
        $session = new ScanSession();
        $session->setUploadTokenOverride('');
        putenv(UploadConfig::ENV_TOKEN . '=env-token');

        $config = $this->makeConfig(
            tokenInConfig: 'config-token',
            session: $session
        );

        // CLI cleared → env wins.
        self::assertSame('env-token', $config->token());
    }

    public function testReturnsEmptyStringWhenNothingConfigured(): void
    {
        $config = $this->makeConfig(
            tokenInConfig: '',
            session: null
        );
        self::assertSame('', $config->token());
    }

    /**
     * @dataProvider truthyEnabledProvider
     */
    public function testEnabledEnvVarAcceptsTruthy(string $value): void
    {
        putenv(UploadConfig::ENV_ENABLED . '=' . $value);
        // Pass admin flag = false; env must still win.
        $config = $this->makeConfig(tokenInConfig: '', session: null, adminEnabled: false);
        self::assertTrue($config->isEnabled(), 'env value "' . $value . '" should be truthy');
    }

    /**
     * @return iterable<array{string}>
     */
    public static function truthyEnabledProvider(): iterable
    {
        yield ['1'];
        yield ['true'];
        yield ['True'];
        yield ['TRUE'];
        yield ['yes'];
        yield ['Yes'];
        yield ['on'];
        yield ['ON'];
    }

    /**
     * @dataProvider falsyEnabledProvider
     */
    public function testEnabledEnvVarAcceptsFalsy(string $value): void
    {
        putenv(UploadConfig::ENV_ENABLED . '=' . $value);
        // Pass admin flag = true; env-disabled MUST override and keep it off.
        $config = $this->makeConfig(tokenInConfig: '', session: null, adminEnabled: true);
        self::assertFalse($config->isEnabled(), 'env value "' . $value . '" should be falsy');
    }

    /**
     * @return iterable<array{string}>
     */
    public static function falsyEnabledProvider(): iterable
    {
        yield ['0'];
        yield ['false'];
        yield ['False'];
        yield ['no'];
        yield ['off'];
    }

    public function testUnknownEnabledEnvFallsThroughToAdminFlag(): void
    {
        putenv(UploadConfig::ENV_ENABLED . '=banana');
        $config = $this->makeConfig(tokenInConfig: '', session: null, adminEnabled: true);
        self::assertTrue($config->isEnabled());

        putenv(UploadConfig::ENV_ENABLED . '=banana');
        $config = $this->makeConfig(tokenInConfig: '', session: null, adminEnabled: false);
        self::assertFalse($config->isEnabled());
    }

    public function testUnsetEnabledEnvFallsThroughToAdminFlag(): void
    {
        putenv(UploadConfig::ENV_ENABLED);
        $config = $this->makeConfig(tokenInConfig: '', session: null, adminEnabled: true);
        self::assertTrue($config->isEnabled());
    }

    // ----- helpers --------------------------------------------------

    private function makeConfig(
        string $tokenInConfig,
        ?ScanSession $session,
        bool $adminEnabled = false
    ): UploadConfig {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(static function (string $path) use ($tokenInConfig) {
            if ($path === UploadConfig::PATH_TOKEN) {
                return $tokenInConfig === '' ? null : 'enc:' . $tokenInConfig;
            }
            return null;
        });
        $scope->method('isSetFlag')->willReturnCallback(static function (string $path) use ($adminEnabled): bool {
            if ($path === UploadConfig::PATH_ENABLED) {
                return $adminEnabled;
            }
            return false;
        });

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(static function (string $enc): string {
            return str_starts_with($enc, 'enc:') ? substr($enc, 4) : $enc;
        });

        return new UploadConfig($scope, $encryptor, $session);
    }
}
