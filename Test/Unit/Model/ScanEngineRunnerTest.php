<?php

/**
 * IronCart_Scan — ScanEngineRunner unit test.
 *
 * Pins the contract documented on
 * {@see \IronCart\Scan\Model\ScanEngineRunner::runAndReport()}:
 *
 *   - calls `CheckRegistry::runAll()` exactly once,
 *   - calls `ProductMetadataInterface::getVersion()` + `getEdition()`
 *     exactly once each (no repeat reads), then
 *   - hands the resulting findings + version + edition to
 *     `ReportBuilder::build()` and returns the assembled result envelope.
 *
 * IronCartLabs/IronCartM2#156 lifted this orchestration out of three
 * inline call-sites; the test guards against a future refactor that
 * accidentally re-introduces a second `getVersion()` round-trip per scan
 * (the consumer's summary previously stored magento.version separately
 * from the report's magento.version — they MUST agree).
 *
 * Lives under Test/Unit/Model/ alongside the runner itself. The
 * Magento-free unit cell in `.github/workflows/ci.yml` restricts the
 * testsuite to `Test/Unit/Report/**`, so this file is exercised by
 * developers running `composer test` locally against a Magento sandbox.
 * The integration cell exercises the runner end-to-end via
 * `bin/magento ironcart:scan --format=json`.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Model;

use IronCart\Scan\Check\CheckRegistry;
use IronCart\Scan\Model\ScanEngineResult;
use IronCart\Scan\Model\ScanEngineRunner;
use IronCart\Scan\Report\ReportBuilder;
use Magento\Framework\App\ProductMetadataInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Model\ScanEngineRunner
 * @covers \IronCart\Scan\Model\ScanEngineResult
 */
class ScanEngineRunnerTest extends TestCase
{
    public function testRunAndReportComposesFindingsVersionAndReport(): void
    {
        $findings = [
            [
                'id' => 'IC-001',
                'title' => 'demo',
                'severity' => 'high',
                'evidence' => null,
                'remediation_url' => 'https://ironcart.dev/r/IC-001',
            ],
        ];

        $registry = $this->createMock(CheckRegistry::class);
        $registry->expects(self::once())
            ->method('runAll')
            ->willReturn($findings);

        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        // Each getter is read exactly once per scan — the runner stores
        // the values on the result envelope so the consumer's summary
        // and the report's magento.* fields never drift.
        $productMetadata->expects(self::once())
            ->method('getVersion')
            ->willReturn('2.4.7-p3');
        $productMetadata->expects(self::once())
            ->method('getEdition')
            ->willReturn('Community');

        $assembled = [
            'schema_version' => 'v0',
            'generated_at' => '2026-01-01T00:00:00Z',
            'magento' => ['version' => '2.4.7-p3', 'edition' => 'Community'],
            'summary' => ['high' => 1],
            'findings' => $findings,
        ];
        $reportBuilder = $this->createMock(ReportBuilder::class);
        $reportBuilder->expects(self::once())
            ->method('build')
            ->with('2.4.7-p3', 'Community', $findings)
            ->willReturn($assembled);

        $runner = new ScanEngineRunner($registry, $reportBuilder, $productMetadata);

        $result = $runner->runAndReport();

        self::assertInstanceOf(ScanEngineResult::class, $result);
        self::assertSame($findings, $result->findings);
        self::assertSame($assembled, $result->report);
        self::assertSame('2.4.7-p3', $result->magentoVersion);
        self::assertSame('Community', $result->magentoEdition);
    }

    public function testRunAndReportPropagatesCheckRegistryFailure(): void
    {
        // If the underlying scan blows up, the runner must NOT swallow
        // the throwable — each caller has its own failure-mode contract
        // (ScanCommand renders a CLI error, UploadScan wraps in
        // RuntimeException, ScanRunConsumer marks the run failed).
        $registry = $this->createMock(CheckRegistry::class);
        $registry->method('runAll')
            ->willThrowException(new \RuntimeException('check blew up'));

        $reportBuilder = $this->createMock(ReportBuilder::class);
        $reportBuilder->expects(self::never())->method('build');

        $productMetadata = $this->createMock(ProductMetadataInterface::class);

        $runner = new ScanEngineRunner($registry, $reportBuilder, $productMetadata);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('check blew up');
        $runner->runAndReport();
    }
}
