<?php

/**
 * IronCart_Scan — continuous-monitoring cron unit tests.
 *
 * Covers the critical invariants documented on IronCartLabs/IronCartM2#64:
 *
 *   - `ironcart_scan/cron/enabled = 0` → handler returns immediately,
 *     does NOT invoke the check registry, does NOT invoke the upload
 *     runner, does NOT emit log lines.
 *   - `ironcart_scan/cron/enabled = 1` → handler runs the full scan +
 *     upload pipeline and logs a success line on a clean run.
 *   - Upload returns 402 (quota_exceeded) → handler logs the
 *     `upgrade_url` returned by the server and throws so the Magento
 *     cron framework marks the schedule row as `error`.
 *
 * The internal {@see UploadRunner} policy itself is covered exhaustively
 * by {@see \IronCart\Scan\Test\Unit\Check\Upload\UploadRunnerTest}; this
 * test focuses on the cron shell — gate, registry-runs-once, logger and
 * thrown-exception shape.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Cron;

use IronCart\Scan\Check\CheckRegistry;
use IronCart\Scan\Check\Upload\UploadClientResult;
use IronCart\Scan\Check\Upload\UploadConfig;
use IronCart\Scan\Check\Upload\UploadPayloadBuilder;
use IronCart\Scan\Check\Upload\UploadRunner;
use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use IronCart\Scan\Cron\UploadScan;
use IronCart\Scan\Report\Severity;
use IronCart\Scan\Test\Unit\Check\Upload\FakeUploadClient;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \IronCart\Scan\Cron\UploadScan
 */
class UploadScanTest extends TestCase
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

    public function testDisabledIsANoOpAndNeverEntersTheCheckRegistry(): void
    {
        $client = new FakeUploadClient();
        $logger = new RecordingLogger();
        $registry = $this->makeRecordingRegistry();
        $cron = $this->makeCron(
            cronEnabled: false,
            uploadEnabled: false,
            token: '',
            client: $client,
            logger: $logger,
            registry: $registry
        );

        $cron->execute();

        self::assertSame(0, $registry->runs, 'Disabled cron MUST NOT load the check registry');
        self::assertSame([], $client->invocations, 'Disabled cron MUST NOT open a socket');
        self::assertSame([], $logger->lines, 'Disabled cron MUST emit no log noise');
    }

    public function testEnabledHappyPathDrivesRegistryUploadAndLogsViewUrl(): void
    {
        $client = new FakeUploadClient();
        // FakeUploadClient defaults to a 200 view_url response when the
        // queue is empty — perfect for the happy path.
        $logger = new RecordingLogger();
        $registry = $this->makeRecordingRegistry();
        $cron = $this->makeCron(
            cronEnabled: true,
            uploadEnabled: true,
            token: 'tok',
            client: $client,
            logger: $logger,
            registry: $registry
        );

        $cron->execute();

        self::assertSame(1, $registry->runs, 'Enabled cron must drive the check registry once');
        self::assertCount(1, $client->invocations, 'Enabled cron must POST exactly once');

        // The recording logger captures (level, message, context) tuples;
        // assert a success line landed.
        $messages = array_map(static fn($l) => $l['message'], $logger->lines);
        self::assertContains('IronCart_Scan: cron upload run starting (continuous monitoring).', $messages);
        self::assertTrue(
            $this->anyMessageMatches($logger, 'cron upload succeeded'),
            'Expected a "cron upload succeeded" info line in: ' . print_r($messages, true)
        );
    }

    public function testEnabledButUploadGroupOffShortCircuitsInsideTheRunner(): void
    {
        // ironcart_scan/cron/enabled = 1, but ironcart_scan/upload/enabled = 0.
        // The cron runs the registry (gate already passed), then the upload
        // runner's own gate fires → outcome.exitCode = EXIT_OK with the
        // "Upload disabled" stdout. The cron logs success (not an error)
        // because no upload was attempted but nothing is broken either —
        // matches the standalone CLI behaviour for the same config combo.
        $client = new FakeUploadClient();
        $logger = new RecordingLogger();
        $registry = $this->makeRecordingRegistry();
        $cron = $this->makeCron(
            cronEnabled: true,
            uploadEnabled: false,
            token: '',
            client: $client,
            logger: $logger,
            registry: $registry
        );

        $cron->execute();

        self::assertSame(1, $registry->runs);
        self::assertSame([], $client->invocations, 'Upload-disabled gate must short-circuit before the socket');
    }

    public function test402ResponseLogsUpgradeMessageAndThrows(): void
    {
        $client = new FakeUploadClient();
        $client->queuedResponses = [
            new UploadClientResult(
                402,
                null,
                UploadClientResult::CATEGORY_QUOTA_EXCEEDED,
                'https://ironcart.dev/pricing?from=cron-402&utm_source=module'
            ),
        ];
        $logger = new RecordingLogger();
        $registry = $this->makeRecordingRegistry();
        $cron = $this->makeCron(
            cronEnabled: true,
            uploadEnabled: true,
            token: 'tok',
            client: $client,
            logger: $logger,
            registry: $registry
        );

        $caught = null;
        try {
            $cron->execute();
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull(
            $caught,
            'A 402 response MUST throw so Magento marks the cron_schedule row as error'
        );
        self::assertStringContainsString('upgrade required', $caught->getMessage());
        self::assertStringContainsString('https://ironcart.dev/pricing', $caught->getMessage());

        // The warning log line carries the upgrade_url in its context
        // payload — operators reading var/log/ironcart_scan.log get the
        // URL even if their alerting tooling truncates the exception
        // message.
        $found = false;
        foreach ($logger->lines as $line) {
            if (
                $line['level'] === 'warning'
                && str_contains($line['message'], 'upgrade required')
                && ($line['context']['upgrade_url'] ?? null)
                === 'https://ironcart.dev/pricing?from=cron-402&utm_source=module'
            ) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Expected a warning log line carrying the upgrade_url in context');
    }

    public function test402ResponseWithoutUpgradeUrlFallsBackToPricingPage(): void
    {
        $client = new FakeUploadClient();
        $client->queuedResponses = [
            // Server returned 402 but somehow no upgrade_url (e.g. an
            // older free-tier gate). The cron must still throw and log
            // the canonical pricing URL.
            new UploadClientResult(402, null, UploadClientResult::CATEGORY_QUOTA_EXCEEDED, null),
        ];
        $logger = new RecordingLogger();
        $cron = $this->makeCron(
            cronEnabled: true,
            uploadEnabled: true,
            token: 'tok',
            client: $client,
            logger: $logger
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('https://ironcart.dev/pricing');
        $cron->execute();
    }

    public function testServerErrorThrowsSoMagentoMarksTheCronRowAsError(): void
    {
        $client = new FakeUploadClient();
        $client->queuedResponses = [
            new UploadClientResult(500, null, UploadClientResult::CATEGORY_SERVER),
            new UploadClientResult(500, null, UploadClientResult::CATEGORY_SERVER),
        ];
        $logger = new RecordingLogger();
        $cron = $this->makeCron(
            cronEnabled: true,
            uploadEnabled: true,
            token: 'tok',
            client: $client,
            logger: $logger
        );

        $this->expectException(RuntimeException::class);
        $cron->execute();
    }

    /**
     * Build a {@see UploadScan} wired around fakes/mocks. Mirrors
     * UploadRunnerTest::makeRunner — production wiring lives in
     * `etc/di.xml`.
     */
    private function makeCron(
        bool $cronEnabled,
        bool $uploadEnabled,
        string $token,
        FakeUploadClient $client,
        LoggerInterface $logger,
        ?RecordingCheckRegistry $registry = null,
        string $baseUrl = 'https://shop.example.com/'
    ): UploadScan {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(
            static function (string $path) use ($cronEnabled, $uploadEnabled): bool {
                return match ($path) {
                    UploadScan::PATH_CRON_ENABLED => $cronEnabled,
                    UploadConfig::PATH_ENABLED => $uploadEnabled,
                    default => false,
                };
            }
        );
        $scope->method('getValue')->willReturnCallback(
            static function (string $path) use ($token, $baseUrl) {
                return match ($path) {
                    UploadConfig::PATH_TOKEN => $token === '' ? null : 'enc:' . $token,
                    UploadConfig::PATH_ENDPOINT => 'https://ironcart.dev/api/scan/ingest',
                    UploadConfig::PATH_ALLOWED_HOST => 'ironcart.dev',
                    'web/secure/base_url' => $baseUrl,
                    'web/unsecure/base_url' => $baseUrl,
                    default => null,
                };
            }
        );

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(static function (string $enc): string {
            return str_starts_with($enc, 'enc:') ? substr($enc, 4) : $enc;
        });

        $config = new UploadConfig($scope, $encryptor);

        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        $productMetadata->method('getVersion')->willReturn('2.4.7-p3');
        $productMetadata->method('getEdition')->willReturn('Community');

        $lockPath = $this->writeJson([
            'packages' => [['name' => 'magento/product-community-edition', 'version' => '2.4.7-p3']],
        ]);
        $lockReader = new ComposerLockReader($lockPath);

        $payloadBuilder = new UploadPayloadBuilder(
            $productMetadata,
            $lockReader,
            $scope,
            'test-cron-module-version'
        );

        $runner = new UploadRunner(
            $config,
            $payloadBuilder,
            $client,
            'test-cron-module-version'
        );

        $registry ??= $this->makeRecordingRegistry();

        return new UploadScan($scope, $registry, $runner, $logger);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeJson(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'iron-cron-');
        if ($path === false) {
            self::fail('Failed to allocate temp file');
        }
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
        $this->tempFiles[] = $path;
        return $path;
    }

    private function makeRecordingRegistry(): RecordingCheckRegistry
    {
        return new RecordingCheckRegistry();
    }

    private function anyMessageMatches(RecordingLogger $logger, string $needle): bool
    {
        foreach ($logger->lines as $line) {
            if (str_contains($line['message'], $needle)) {
                return true;
            }
        }
        return false;
    }
}

/**
 * Recording stand-in for {@see CheckRegistry} that counts `runAll()`
 * invocations without loading any production check classes. Returns a
 * single benign finding so the upload pipeline has something to send.
 */
final class RecordingCheckRegistry extends CheckRegistry
{
    public int $runs = 0;

    public function __construct()
    {
        parent::__construct([]);
    }

    /**
     * @return list<array{
     *     id:string,
     *     title:string,
     *     severity:string,
     *     evidence:mixed,
     *     remediation_url:string
     * }>
     */
    public function runAll(): array
    {
        $this->runs++;
        return [[
            'id' => 'IC-020',
            'title' => 'mage mode',
            'severity' => Severity::CRITICAL,
            'evidence' => ['mage_mode' => 'developer'],
            'remediation_url' => 'https://ironcart.dev/docs/checks/IC-020',
        ]];
    }
}

/**
 * In-memory {@see LoggerInterface} that records every call as a (level,
 * message, context) tuple so tests can assert on the cron's log output
 * without driving a real Monolog handler.
 */
final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{level:string,message:string,context:array<string,mixed>}> */
    public array $lines = [];

    public function emergency($message, array $context = []): void
    {
        $this->record('emergency', (string) $message, $context);
    }
    public function alert($message, array $context = []): void
    {
        $this->record('alert', (string) $message, $context);
    }
    public function critical($message, array $context = []): void
    {
        $this->record('critical', (string) $message, $context);
    }
    public function error($message, array $context = []): void
    {
        $this->record('error', (string) $message, $context);
    }
    public function warning($message, array $context = []): void
    {
        $this->record('warning', (string) $message, $context);
    }
    public function notice($message, array $context = []): void
    {
        $this->record('notice', (string) $message, $context);
    }
    public function info($message, array $context = []): void
    {
        $this->record('info', (string) $message, $context);
    }
    public function debug($message, array $context = []): void
    {
        $this->record('debug', (string) $message, $context);
    }
    public function log($level, $message, array $context = []): void
    {
        $this->record((string) $level, (string) $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function record(string $level, string $message, array $context): void
    {
        $this->lines[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }
}
