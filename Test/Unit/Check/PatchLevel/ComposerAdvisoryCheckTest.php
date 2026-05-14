<?php

/**
 * IronCart_Scan — IC-002 unit tests.
 *
 * Exercises composer.lock parsing, OSV snapshot lookup, range matching,
 * and the "older advisory → higher severity" grading rules documented
 * in {@see \IronCart\Scan\Check\PatchLevel\ComposerAdvisoryCheck}.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\PatchLevel;

use DateTimeImmutable;
use DateTimeZone;
use IronCart\Scan\Check\PatchLevel\ComposerAdvisoryCheck;
use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use IronCart\Scan\Check\PatchLevel\OsvSnapshotLoader;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\PatchLevel\ComposerAdvisoryCheck
 * @covers \IronCart\Scan\Check\PatchLevel\ComposerLockReader
 * @covers \IronCart\Scan\Check\PatchLevel\OsvSnapshotLoader
 */
class ComposerAdvisoryCheckTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
    }

    public function testReportsVulnerablePackageAsCriticalWhenAdvisoryIsOld(): void
    {
        $lock = $this->writeJson([
            'packages' => [
                ['name' => 'magento/product-community-edition', 'version' => '2.4.7'],
            ],
        ]);
        $snapshot = $this->writeJson([
            'schema_version' => 'v0',
            'advisories' => [[
                'id' => 'GHSA-test',
                'aliases' => ['CVE-2024-99999'],
                'summary' => 'Test advisory',
                'published' => '2024-01-01',
                'severity' => 'critical',
                'package' => 'magento/product-community-edition',
                'affected' => [['introduced' => '2.4.7', 'fixed' => '2.4.7-p3']],
                'reference' => 'https://example.test/advisory',
            ]],
        ]);

        $findings = $this->makeCheck($lock, $snapshot, '2025-01-01')->run();

        self::assertCount(1, $findings);
        $finding = $findings[0];
        self::assertSame('IC-002', $finding['id']);
        self::assertSame(Severity::CRITICAL, $finding['severity']);
        self::assertSame('magento/product-community-edition', $finding['evidence']['package']);
        self::assertSame('2.4.7', $finding['evidence']['installed_version']);
        self::assertContains('2.4.7-p3', $finding['evidence']['fixed_in']);
    }

    public function testSkipsPackagesAlreadyOnAFixedVersion(): void
    {
        $lock = $this->writeJson([
            'packages' => [
                ['name' => 'magento/product-community-edition', 'version' => '2.4.7-p3'],
            ],
        ]);
        $snapshot = $this->writeJson([
            'schema_version' => 'v0',
            'advisories' => [[
                'id' => 'GHSA-test',
                'aliases' => [],
                'summary' => 'Already patched',
                'published' => '2024-06-01',
                'severity' => 'high',
                'package' => 'magento/product-community-edition',
                'affected' => [['introduced' => '2.4.7', 'fixed' => '2.4.7-p3']],
                'reference' => 'https://example.test/advisory',
            ]],
        ]);

        self::assertSame([], $this->makeCheck($lock, $snapshot, '2025-01-01')->run());
    }

    public function testRespectsDeclaredSeverityWhenWorseThanAge(): void
    {
        $lock = $this->writeJson([
            'packages' => [
                ['name' => 'vendor/pkg', 'version' => '1.0.0'],
            ],
        ]);
        $snapshot = $this->writeJson([
            'schema_version' => 'v0',
            'advisories' => [[
                'id' => 'GHSA-fresh-critical',
                'aliases' => [],
                'summary' => 'New critical',
                'published' => '2025-01-10',
                'severity' => 'critical',
                'package' => 'vendor/pkg',
                'affected' => [['introduced' => '0', 'fixed' => '1.1.0']],
                'reference' => 'https://example.test/fresh',
            ]],
        ]);

        // Published 5 days before "now" → age-grade would be MEDIUM, but
        // the OSV-declared CRITICAL must win.
        $finding = $this->makeCheck($lock, $snapshot, '2025-01-15')->run()[0];
        self::assertSame(Severity::CRITICAL, $finding['severity']);
    }

    public function testEscalatesAgeSeverityAboveDeclared(): void
    {
        $lock = $this->writeJson([
            'packages' => [
                ['name' => 'vendor/pkg', 'version' => '1.0.0'],
            ],
        ]);
        $snapshot = $this->writeJson([
            'schema_version' => 'v0',
            'advisories' => [[
                'id' => 'GHSA-old-medium',
                'aliases' => [],
                'summary' => 'Aging medium',
                'published' => '2024-01-01',
                'severity' => 'medium',
                'package' => 'vendor/pkg',
                'affected' => [['introduced' => '0', 'fixed' => '1.1.0']],
                'reference' => 'https://example.test/old',
            ]],
        ]);

        // ~365 days old → age-grade CRITICAL trumps the declared MEDIUM.
        $finding = $this->makeCheck($lock, $snapshot, '2025-01-01')->run()[0];
        self::assertSame(Severity::CRITICAL, $finding['severity']);
    }

    public function testIncludesDevPackages(): void
    {
        $lock = $this->writeJson([
            'packages' => [],
            'packages-dev' => [
                ['name' => 'vendor/dev', 'version' => '1.0.0'],
            ],
        ]);
        $snapshot = $this->writeJson([
            'schema_version' => 'v0',
            'advisories' => [[
                'id' => 'GHSA-dev',
                'aliases' => [],
                'summary' => 'Dev tool advisory',
                'published' => '2024-06-01',
                'severity' => 'high',
                'package' => 'vendor/dev',
                'affected' => [['introduced' => '0', 'fixed' => '1.1.0']],
                'reference' => 'https://example.test/dev',
            ]],
        ]);

        $findings = $this->makeCheck($lock, $snapshot, '2025-01-01')->run();
        self::assertCount(1, $findings);
        self::assertSame('vendor/dev', $findings[0]['evidence']['package']);
    }

    public function testStripsLeadingVFromComposerVersions(): void
    {
        $lock = $this->writeJson([
            'packages' => [
                ['name' => 'vendor/v-prefixed', 'version' => 'v1.0.0'],
            ],
        ]);
        $snapshot = $this->writeJson([
            'schema_version' => 'v0',
            'advisories' => [[
                'id' => 'GHSA-v',
                'aliases' => [],
                'summary' => 'Affects 1.0.0',
                'published' => '2024-06-01',
                'severity' => 'high',
                'package' => 'vendor/v-prefixed',
                'affected' => [['introduced' => '0', 'fixed' => '1.0.1']],
                'reference' => '',
            ]],
        ]);

        $finding = $this->makeCheck($lock, $snapshot, '2025-01-01')->run()[0];
        self::assertSame('1.0.0', $finding['evidence']['installed_version']);
    }

    public function testSurfacesMissingLockfileAsInfo(): void
    {
        $snapshot = $this->writeJson([
            'schema_version' => 'v0',
            'advisories' => [],
        ]);

        $check = new ComposerAdvisoryCheck(
            new ComposerLockReader('/nonexistent/path/composer.lock'),
            new OsvSnapshotLoader($snapshot),
            new DateTimeImmutable('2025-01-01', new DateTimeZone('UTC'))
        );

        $findings = $check->run();
        self::assertCount(1, $findings);
        self::assertSame(Severity::INFO, $findings[0]['severity']);
        self::assertSame('composer.lock unavailable', $findings[0]['evidence']['status']);
    }

    public function testFallsBackToOsvUrlWhenReferenceMissing(): void
    {
        $lock = $this->writeJson([
            'packages' => [
                ['name' => 'vendor/no-ref', 'version' => '1.0.0'],
            ],
        ]);
        $snapshot = $this->writeJson([
            'schema_version' => 'v0',
            'advisories' => [[
                'id' => 'GHSA-noref',
                'aliases' => [],
                'summary' => '',
                'published' => '2024-06-01',
                'severity' => 'high',
                'package' => 'vendor/no-ref',
                'affected' => [['introduced' => '0', 'fixed' => '1.0.1']],
                'reference' => '',
            ]],
        ]);

        $finding = $this->makeCheck($lock, $snapshot, '2025-01-01')->run()[0];
        self::assertSame('https://osv.dev/vulnerability/GHSA-noref', $finding['remediation_url']);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeJson(array $payload): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ironcart-test-');
        if ($path === false) {
            self::fail('Unable to create tempfile.');
        }
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->tempFiles[] = $path;
        return $path;
    }

    private function makeCheck(string $lockPath, string $snapshotPath, string $now): ComposerAdvisoryCheck
    {
        return new ComposerAdvisoryCheck(
            new ComposerLockReader($lockPath),
            new OsvSnapshotLoader($snapshotPath),
            new DateTimeImmutable($now, new DateTimeZone('UTC'))
        );
    }
}
