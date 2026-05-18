<?php

/**
 * IronCart_Scan — ReportBuilder unit tests.
 *
 * Pins the report schema so an accidental shape change shows up as a
 * failing test rather than a silent client break. Covers both the v0
 * baseline (untouched keys + values) and the v1 deprecation additions
 * (issue #83).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Report;

use IronCart\Scan\Check\DeprecationRegistry;
use IronCart\Scan\Report\ReportBuilder;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

class ReportBuilderTest extends TestCase
{
    public function testEmptyReportHasFrozenShape(): void
    {
        $builder = new ReportBuilder();
        $report = $builder->build('2.4.7-p3', 'Community', []);

        $this->assertSame('v1', $report['schema_version']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $report['generated_at']
        );
        $this->assertSame(
            ['version' => '2.4.7-p3', 'edition' => 'Community'],
            $report['magento']
        );
        $this->assertSame(
            [
                Severity::CRITICAL => 0,
                Severity::HIGH => 0,
                Severity::MEDIUM => 0,
                Severity::LOW => 0,
                Severity::INFO => 0,
                ReportBuilder::SUMMARY_DEPRECATED_KEY => 0,
            ],
            $report['summary']
        );
        $this->assertSame([], $report['findings']);
    }

    public function testSummaryTalliesFindingsBySeverity(): void
    {
        $builder = new ReportBuilder();
        $findings = [
            $this->finding('IC-1', Severity::CRITICAL),
            $this->finding('IC-2', Severity::HIGH),
            $this->finding('IC-3', Severity::HIGH),
            $this->finding('IC-4', Severity::INFO),
        ];

        $report = $builder->build('2.4.6', 'Enterprise', $findings);

        $this->assertSame(
            [
                Severity::CRITICAL => 1,
                Severity::HIGH => 2,
                Severity::MEDIUM => 0,
                Severity::LOW => 0,
                Severity::INFO => 1,
                ReportBuilder::SUMMARY_DEPRECATED_KEY => 0,
            ],
            $report['summary']
        );
        $this->assertCount(4, $report['findings']);
        $this->assertSame('IC-1', $report['findings'][0]['id']);
    }

    public function testUnknownSeverityIsNotCounted(): void
    {
        $builder = new ReportBuilder();
        $findings = [
            $this->finding('IC-1', 'bogus'),
        ];

        $report = $builder->build('2.4.7', 'Community', $findings);

        $this->assertSame(
            [
                Severity::CRITICAL => 0,
                Severity::HIGH => 0,
                Severity::MEDIUM => 0,
                Severity::LOW => 0,
                Severity::INFO => 0,
                ReportBuilder::SUMMARY_DEPRECATED_KEY => 0,
            ],
            $report['summary']
        );
    }

    public function testDeprecatedFindingsAreDecoratedAdditively(): void
    {
        $builder = new ReportBuilder(new DeprecationRegistry());
        $findings = [
            $this->finding('IC-060', Severity::HIGH),
            // Untouched check id — must remain v0-shape.
            $this->finding('IC-001', Severity::HIGH),
        ];

        $report = $builder->build('2.4.7-p3', 'Community', $findings);

        // Schema bump advertised.
        $this->assertSame('v1', $report['schema_version']);

        // Deprecated finding gained the 4 optional keys.
        $deprecated = $report['findings'][0];
        $this->assertSame('IC-060', $deprecated['id']);
        $this->assertSame(DeprecationRegistry::DEPRECATED_IN, $deprecated['deprecated_in']);
        $this->assertSame(DeprecationRegistry::REMOVAL_IN, $deprecated['removal_in']);
        $this->assertSame(
            DeprecationRegistry::REPLACEMENT_PACKAGE,
            $deprecated['replacement']
        );
        $this->assertSame(
            DeprecationRegistry::MIGRATION_URL,
            $deprecated['migration_url']
        );

        // Untouched finding has none of the new keys (v0 byte-identical).
        $untouched = $report['findings'][1];
        $this->assertSame('IC-001', $untouched['id']);
        $this->assertArrayNotHasKey('deprecated_in', $untouched);
        $this->assertArrayNotHasKey('removal_in', $untouched);
        $this->assertArrayNotHasKey('replacement', $untouched);
        $this->assertArrayNotHasKey('migration_url', $untouched);

        // Summary deprecated count == number of decorated findings.
        $this->assertSame(1, $report['summary'][ReportBuilder::SUMMARY_DEPRECATED_KEY]);
    }

    public function testDeprecatedFallbackIdsAlsoDecorated(): void
    {
        $builder = new ReportBuilder(new DeprecationRegistry());
        // IC-061 is the IC-060 transport-failure fallback; same lifecycle.
        // IC-071 / IC-073 are the IC-070 / IC-072 missing-manifest fallbacks.
        $findings = [
            $this->finding('IC-061', Severity::LOW),
            $this->finding('IC-071', Severity::LOW),
            $this->finding('IC-073', Severity::LOW),
        ];

        $report = $builder->build('2.4.7', 'Community', $findings);

        $this->assertSame(3, $report['summary'][ReportBuilder::SUMMARY_DEPRECATED_KEY]);
        foreach ($report['findings'] as $finding) {
            $this->assertArrayHasKey('deprecated_in', $finding);
            $this->assertArrayHasKey('removal_in', $finding);
            $this->assertArrayHasKey('replacement', $finding);
            $this->assertArrayHasKey('migration_url', $finding);
        }
    }

    public function testWithoutRegistryFindingsArePassedThroughUntouched(): void
    {
        // No registry wired (e.g. legacy test fixture). Even an IC-060
        // finding must keep its v0 shape — the builder is read-only and
        // never invents deprecation metadata it has no source for.
        $builder = new ReportBuilder();
        $findings = [$this->finding('IC-060', Severity::HIGH)];

        $report = $builder->build('2.4.7', 'Community', $findings);

        $finding = $report['findings'][0];
        $this->assertArrayNotHasKey('deprecated_in', $finding);
        $this->assertSame(0, $report['summary'][ReportBuilder::SUMMARY_DEPRECATED_KEY]);
    }

    /**
     * @return array{id:string,title:string,severity:string,evidence:array<string,mixed>,remediation_url:string}
     */
    private function finding(string $id, string $severity): array
    {
        return [
            'id' => $id,
            'title' => 'Test finding ' . $id,
            'severity' => $severity,
            'evidence' => ['scope' => 'unit-test'],
            'remediation_url' => 'https://ironcart.dev/docs/' . $id,
        ];
    }
}
