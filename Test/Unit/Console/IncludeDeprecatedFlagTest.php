<?php

/**
 * IronCart_Scan — `--include-deprecated` CLI flag tests (issue #83).
 *
 * Drives the ScanCommand end-to-end through Symfony's CommandTester to
 * verify:
 *
 *   1. The flag defaults to TRUE in v1.x — deprecated checks run, the
 *      operator sees one stderr notice per ran deprecated check.
 *   2. `--include-deprecated=false` suppresses both the run AND the
 *      stderr notice.
 *   3. The JSON report carries the v1 deprecation fields
 *      (deprecated_in, removal_in, replacement, migration_url) on each
 *      deprecated-check finding when the check ran.
 *
 * The stderr/stdout separation matters: operators pipe `--format=json`
 * into `jq` and must NOT see the deprecation notice break that pipe.
 * Symfony Console writes the notice via `getErrorOutput()`; the
 * CommandTester captures STDERR with the `decorated/capture-stderr` mode.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Console;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\CheckRegistry;
use IronCart\Scan\Check\DeprecationRegistry;
use IronCart\Scan\Check\ScanSession;
use IronCart\Scan\Check\Upload\UploadConfig;
use IronCart\Scan\Check\Upload\UploadPayloadBuilder;
use IronCart\Scan\Check\Upload\UploadRunner;
use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use IronCart\Scan\Console\Command\ScanCommand;
use IronCart\Scan\Report\ReportBuilder;
use IronCart\Scan\Report\ReportRenderer;
use IronCart\Scan\Report\Severity;
use IronCart\Scan\Test\Unit\Check\Upload\FakeUploadClient;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \IronCart\Scan\Console\Command\ScanCommand
 * @covers \IronCart\Scan\Check\DeprecationRegistry
 * @covers \IronCart\Scan\Check\CheckRegistry
 * @covers \IronCart\Scan\Report\ReportBuilder
 */
class IncludeDeprecatedFlagTest extends TestCase
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

    public function testDeprecatedCheckRunsByDefaultAndEmitsStderrNotice(): void
    {
        $tester = $this->makeTester([
            'IC-060' => $this->stubCheck([$this->finding('IC-060', Severity::HIGH, 'CVE finding')]),
            'IC-001' => $this->stubCheck([$this->finding('IC-001', Severity::HIGH, 'patch finding')]),
        ]);

        $exit = $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertSame(ScanCommand::EXIT_OK, $exit);

        $stderr = $tester->getErrorOutput();
        self::assertStringContainsString('[DEPRECATED]', $stderr);
        self::assertStringContainsString('IC-060', $stderr);
        self::assertStringContainsString(
            'ironcartlabs/magento-scan-pro',
            $stderr,
            'Notice must name the replacement package operators migrate to'
        );
        self::assertStringContainsString(
            DeprecationRegistry::MIGRATION_URL,
            $stderr,
            'Notice must link the public migration doc'
        );

        // stdout (the report) must NOT contain the deprecation notice
        // — operators piping `jq` would otherwise see corrupt JSON.
        $stdout = $tester->getDisplay();
        self::assertStringNotContainsString('[DEPRECATED]', $stdout);

        $report = $this->extractJsonReport($stdout);
        self::assertSame('v1', $report['schema_version']);
        self::assertSame(1, $report['summary']['deprecated']);

        // Deprecated finding carries the additive fields.
        $deprecated = $this->findById($report['findings'], 'IC-060');
        self::assertSame(DeprecationRegistry::DEPRECATED_IN, $deprecated['deprecated_in']);
        self::assertSame(DeprecationRegistry::REMOVAL_IN, $deprecated['removal_in']);
        self::assertSame(
            DeprecationRegistry::REPLACEMENT_PACKAGE,
            $deprecated['replacement']
        );

        // Non-deprecated finding does not.
        $live = $this->findById($report['findings'], 'IC-001');
        self::assertArrayNotHasKey('deprecated_in', $live);
    }

    public function testIncludeDeprecatedFalseSuppressesRunAndNotice(): void
    {
        $tester = $this->makeTester([
            'IC-060' => $this->stubCheck([$this->finding('IC-060', Severity::HIGH, 'CVE finding')]),
            'IC-001' => $this->stubCheck([$this->finding('IC-001', Severity::HIGH, 'patch finding')]),
        ]);

        $exit = $tester->execute(
            ['--include-deprecated' => 'false'],
            ['capture_stderr_separately' => true]
        );

        self::assertSame(ScanCommand::EXIT_OK, $exit);

        // Stderr must be silent — the operator opted out, no nag.
        $stderr = $tester->getErrorOutput();
        self::assertStringNotContainsString(
            '[DEPRECATED]',
            $stderr,
            '--include-deprecated=false suppresses the stderr notice'
        );

        // Stdout: the deprecated check is missing from the report entirely.
        $report = $this->extractJsonReport($tester->getDisplay());
        self::assertSame(0, $report['summary']['deprecated']);
        self::assertNull(
            $this->findByIdOrNull($report['findings'], 'IC-060'),
            'IC-060 must NOT appear in the report when opted out'
        );
        self::assertNotNull(
            $this->findByIdOrNull($report['findings'], 'IC-001'),
            'Non-deprecated checks remain unaffected'
        );
    }

    public function testIncludeDeprecatedTrueIsTheV1XDefaultExplicitlySetting(): void
    {
        // Belt-and-braces — even if Symfony's default-value handling
        // changes upstream, explicitly passing `true` must behave
        // identically to the implicit default.
        $tester = $this->makeTester([
            'IC-060' => $this->stubCheck([$this->finding('IC-060', Severity::HIGH, 'CVE finding')]),
        ]);

        $exit = $tester->execute(
            ['--include-deprecated' => 'true'],
            ['capture_stderr_separately' => true]
        );

        self::assertSame(ScanCommand::EXIT_OK, $exit);
        self::assertStringContainsString('[DEPRECATED]', $tester->getErrorOutput());
        $report = $this->extractJsonReport($tester->getDisplay());
        self::assertSame(1, $report['summary']['deprecated']);
    }

    /**
     * @param array<string, CheckInterface> $checks
     */
    private function makeTester(array $checks): CommandTester
    {
        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        $productMetadata->method('getVersion')->willReturn('2.4.7-p3');
        $productMetadata->method('getEdition')->willReturn('Community');

        $deprecations = new DeprecationRegistry();
        $reportBuilder = new ReportBuilder($deprecations);
        $reportRenderer = new ReportRenderer();
        $registry = new CheckRegistry($checks, $deprecations);
        $session = new ScanSession();

        // The upload pipeline is irrelevant to this test — wire fakes
        // identical to those used by UploadFlagTest so the command's
        // constructor signature is satisfied.
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn(false);
        $scope->method('getValue')->willReturn(null);

        $encryptor = $this->createMock(EncryptorInterface::class);
        $config = new UploadConfig($scope, $encryptor);

        $lockPath = tempnam(sys_get_temp_dir(), 'iron-dep-');
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
            'test-deprecation-module-version'
        );
        $runner = new UploadRunner(
            $config,
            $payloadBuilder,
            new FakeUploadClient(),
            'test-deprecation-module-version'
        );

        $command = new ScanCommand(
            $productMetadata,
            $reportBuilder,
            $reportRenderer,
            $registry,
            $session,
            $runner,
            $deprecations
        );

        return new CommandTester($command);
    }

    /**
     * The text-mode wrapper emits a Magento-banner block; the JSON-mode
     * default just dumps the report. Use `--format=json` for the
     * deprecation tests because the v1 deprecation fields are
     * JSON-specific.
     */
    private function extractJsonReport(string $stdout): array
    {
        // CommandTester collapses the report stream and the
        // "Report written to ..." line; locate the JSON object.
        $start = strpos($stdout, '{');
        $end = strrpos($stdout, '}');
        self::assertNotFalse($start, 'stdout must include a JSON object');
        self::assertNotFalse($end, 'stdout must include a JSON object');

        $json = substr($stdout, $start, $end - $start + 1);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param list<array<string,mixed>> $findings
     *
     * @return array<string,mixed>
     */
    private function findById(array $findings, string $id): array
    {
        $hit = $this->findByIdOrNull($findings, $id);
        self::assertNotNull($hit, "Finding {$id} expected in report");
        return $hit;
    }

    /**
     * @param list<array<string,mixed>> $findings
     *
     * @return array<string,mixed>|null
     */
    private function findByIdOrNull(array $findings, string $id): ?array
    {
        foreach ($findings as $finding) {
            if (($finding['id'] ?? null) === $id) {
                return $finding;
            }
        }
        return null;
    }

    /**
     * @param list<array{id:string,title:string,severity:string,evidence:mixed,remediation_url:string}> $findings
     */
    private function stubCheck(array $findings): CheckInterface
    {
        return new class ($findings) implements CheckInterface {
            /**
             * @param list<array{id:string,title:string,severity:string,evidence:mixed,remediation_url:string}> $findings
             */
            public function __construct(private readonly array $findings)
            {
            }

            public function run(): array
            {
                return $this->findings;
            }
        };
    }

    /**
     * @return array{id:string,title:string,severity:string,evidence:mixed,remediation_url:string}
     */
    private function finding(string $id, string $severity, string $title): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'severity' => $severity,
            'evidence' => [],
            'remediation_url' => 'https://ironcart.dev/docs/checks/' . $id,
        ];
    }
}
