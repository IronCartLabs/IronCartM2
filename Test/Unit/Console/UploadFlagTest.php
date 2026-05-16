<?php

/**
 * IronCart_Scan — `bin/magento ironcart:scan --upload` CLI integration tests.
 *
 * Drives the ScanCommand end-to-end through Symfony's CommandTester to
 * verify the upload flag exposes the right exit codes and stdout/stderr
 * shape. The internal {@see UploadRunner} policy is covered exhaustively
 * by {@see \IronCart\Scan\Test\Unit\Check\Upload\UploadRunnerTest}; this
 * file focuses on the CLI shell — Symfony option wiring, command exit
 * codes, output channels.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Console;

use IronCart\Scan\Check\CheckRegistry;
use IronCart\Scan\Check\ScanSession;
use IronCart\Scan\Check\Upload\UploadClient;
use IronCart\Scan\Check\Upload\UploadClientResult;
use IronCart\Scan\Check\Upload\UploadConfig;
use IronCart\Scan\Check\Upload\UploadPayloadBuilder;
use IronCart\Scan\Check\Upload\UploadRunner;
use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use IronCart\Scan\Console\Command\ScanCommand;
use IronCart\Scan\Report\ReportBuilder;
use IronCart\Scan\Report\ReportRenderer;
use IronCart\Scan\Test\Unit\Check\Upload\FakeUploadClient;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \IronCart\Scan\Console\Command\ScanCommand
 */
class UploadFlagTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
    }

    public function testUploadFlagOmittedExitsZeroAndNeverInvokesClient(): void
    {
        $client = new FakeUploadClient();
        $tester = $this->makeTester($client, enabled: true, token: 'tok');

        $exit = $tester->execute([]);

        self::assertSame(ScanCommand::EXIT_OK, $exit);
        self::assertSame([], $client->invocations, '--upload omitted MUST never open a socket');
    }

    public function testUploadDisabledExitsZeroAndSkipsCleanly(): void
    {
        $client = new FakeUploadClient();
        $tester = $this->makeTester($client, enabled: false, token: '');

        $exit = $tester->execute(['--upload' => true]);

        self::assertSame(ScanCommand::EXIT_OK, $exit);
        self::assertSame([], $client->invocations);
        self::assertStringContainsString('Upload disabled', $tester->getDisplay());
    }

    public function testUploadEnabledNoTokenExitsNonZero(): void
    {
        $client = new FakeUploadClient();
        $tester = $this->makeTester($client, enabled: true, token: '');

        $exit = $tester->execute(['--upload' => true]);

        self::assertNotSame(ScanCommand::EXIT_OK, $exit);
        self::assertSame([], $client->invocations);
    }

    public function testUploadHappyPathPrintsViewUrl(): void
    {
        $client = new FakeUploadClient();
        $client->queuedResponses = [
            new UploadClientResult(200, 'https://ironcart.dev/scan/abc', UploadClientResult::CATEGORY_OK),
        ];
        $tester = $this->makeTester($client, enabled: true, token: 'real-tok');

        $exit = $tester->execute(['--upload' => true]);

        self::assertSame(ScanCommand::EXIT_OK, $exit);
        self::assertCount(1, $client->invocations);
        self::assertStringContainsString('https://ironcart.dev/scan/abc', $tester->getDisplay());
    }

    public function testUpload401DoesNotLeakResponseBody(): void
    {
        $client = new FakeUploadClient();
        $client->queuedResponses = [
            new UploadClientResult(401, null, UploadClientResult::CATEGORY_AUTH),
        ];
        $tester = $this->makeTester($client, enabled: true, token: 'bad-tok');

        $exit = $tester->execute(['--upload' => true]);

        self::assertNotSame(ScanCommand::EXIT_OK, $exit);
        $display = $tester->getDisplay();
        // Stable category label appears; raw response body / JSON does not.
        self::assertStringNotContainsString('{"error"', $display);
    }

    /**
     * Build a CommandTester around the ScanCommand with fakes wired
     * for ScanSession + ProductMetadata + ScopeConfig + Encryptor.
     */
    private function makeTester(
        FakeUploadClient $client,
        bool $enabled,
        string $token
    ): CommandTester {
        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        $productMetadata->method('getVersion')->willReturn('2.4.7-p3');
        $productMetadata->method('getEdition')->willReturn('Community');

        $reportBuilder = new ReportBuilder();
        $reportRenderer = new ReportRenderer();

        // Empty registry — the CLI doesn't care about findings for these
        // tests, only that the --upload flag drives the runner.
        $registry = new CheckRegistry([]);
        $session = new ScanSession();

        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnMap([
            [UploadConfig::PATH_ENABLED, 'default', null, $enabled],
        ]);
        $scope->method('getValue')->willReturnCallback(static function (string $path) use ($token) {
            return match ($path) {
                UploadConfig::PATH_TOKEN => $token === '' ? null : 'enc:' . $token,
                UploadConfig::PATH_ENDPOINT => 'https://ironcart.dev/api/scan/ingest',
                UploadConfig::PATH_ALLOWED_HOST => 'ironcart.dev',
                'web/secure/base_url' => 'https://shop.example.com/',
                'web/unsecure/base_url' => 'http://shop.example.com/',
                default => null,
            };
        });

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(static function (string $enc): string {
            return str_starts_with($enc, 'enc:') ? substr($enc, 4) : $enc;
        });

        $config = new UploadConfig($scope, $encryptor);

        $lockPath = tempnam(sys_get_temp_dir(), 'iron-cli-');
        if ($lockPath === false) {
            self::fail('Failed to allocate temp file');
        }
        file_put_contents($lockPath, json_encode([
            'packages' => [['name' => 'magento/product-community-edition', 'version' => '2.4.7-p3']],
        ], JSON_THROW_ON_ERROR));
        $this->tempFiles[] = $lockPath;

        $payloadBuilder = new UploadPayloadBuilder(
            $productMetadata,
            new ComposerLockReader($lockPath),
            $scope,
            'test-cli-module-version'
        );

        $runner = new UploadRunner($config, $payloadBuilder, $client, 'test-cli-module-version');

        $command = new ScanCommand(
            $productMetadata,
            $reportBuilder,
            $reportRenderer,
            $registry,
            $session,
            $runner
        );

        return new CommandTester($command);
    }
}
