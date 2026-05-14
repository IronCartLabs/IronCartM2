<?php

/**
 * IronCart_Scan — ReportBuilder unit tests.
 *
 * Pins the v0 report schema so an accidental shape change shows up as a
 * failing test rather than a silent client break.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Report;

use IronCart\Scan\Report\ReportBuilder;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

class ReportBuilderTest extends TestCase
{
    public function testEmptyReportHasFrozenShape(): void
    {
        $builder = new ReportBuilder();
        $report = $builder->build('2.4.7-p3', 'Community', []);

        $this->assertSame('v0', $report['schema_version']);
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
            ],
            $report['summary']
        );
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
