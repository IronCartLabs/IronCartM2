<?php

/**
 * IronCart_Scan — Finding helper unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check;

use InvalidArgumentException;
use IronCart\Scan\Check\Finding;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

final class FindingTest extends TestCase
{
    public function testMakeProducesCanonicalShape(): void
    {
        $finding = Finding::make(
            id: 'IC-040',
            title: 'Indexer stuck',
            severity: Severity::MEDIUM,
            evidence: ['indexers' => ['catalog_product_price']],
            remediationUrl: 'https://example.test/'
        );

        self::assertSame([
            'id' => 'IC-040',
            'title' => 'Indexer stuck',
            'severity' => 'medium',
            'evidence' => ['indexers' => ['catalog_product_price']],
            'remediation_url' => 'https://example.test/',
        ], $finding);
    }

    public function testMakeDefaultsEvidenceAndRemediationUrl(): void
    {
        $finding = Finding::make('IC-X', 'Title', Severity::INFO);

        self::assertNull($finding['evidence']);
        self::assertSame('', $finding['remediation_url']);
    }

    public function testMakeRejectsUnknownSeverity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Finding::make('IC-X', 'T', 'urgent');
    }
}
