<?php

/**
 * IronCart_Scan — ReportRenderer unit tests.
 *
 * Covers the JSON and text branches of the renderer.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Report;

use IronCart\Scan\Report\ReportBuilder;
use IronCart\Scan\Report\ReportRenderer;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class ReportRendererTest extends TestCase
{
    public function testJsonRenderRoundTripsThroughDecode(): void
    {
        $renderer = new ReportRenderer();
        $report = (new ReportBuilder())->build('2.4.7', 'Community', []);

        $output = new BufferedOutput();
        $rendered = $renderer->render($report, 'json', $output);

        $this->assertSame('', $output->fetch(), 'JSON renderer must not write to the console.');
        $decoded = json_decode($rendered, true);
        $this->assertIsArray($decoded);
        $this->assertSame('v0', $decoded['schema_version']);
        $this->assertSame([], $decoded['findings']);
    }

    public function testTextRenderEmitsGroupedSummary(): void
    {
        $renderer = new ReportRenderer();
        $report = (new ReportBuilder())->build('2.4.7', 'Community', [
            [
                'id' => 'IC-99',
                'title' => 'Example finding',
                'severity' => Severity::HIGH,
                'evidence' => [],
                'remediation_url' => 'https://ironcart.dev/docs/IC-99',
            ],
        ]);

        $output = new BufferedOutput();
        $plain = $renderer->render($report, 'text', $output);

        $console = $output->fetch();
        $this->assertStringContainsString('Ironcart scan — Magento 2.4.7', $console);
        $this->assertStringContainsString('critical', $console);
        $this->assertStringContainsString('high', $console);
        $this->assertStringContainsString('Example finding (IC-99)', $console);

        // Plain copy (for --output=<path>) must not contain Symfony tags.
        $this->assertDoesNotMatchRegularExpression('#</?[a-zA-Z]#', $plain);
        $this->assertStringContainsString('Example finding (IC-99)', $plain);
    }

    public function testUnsupportedFormatThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $renderer = new ReportRenderer();
        $report = (new ReportBuilder())->build('2.4.7', 'Community', []);
        $renderer->render($report, 'xml', new BufferedOutput());
    }
}
