<?php

/**
 * IronCart_Scan — IC-070 unit tests.
 *
 * Exercises the mismatch / missing / ignored / mass-tampering / unsupported
 * code paths against synthetic manifests in a tmpdir sandbox. The fixtures
 * are intentionally tiny — IC-070's real performance characteristics are
 * validated by the integration cell in CI.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\FileIntegrity;

use IronCart\Scan\Check\FileIntegrity\CoreFileIntegrityCheck;
use IronCart\Scan\Check\FileIntegrity\ManifestRepository;
use IronCart\Scan\Report\Severity;
use IronCart\Scan\Test\Unit\Check\Filesystem\FilesystemSandbox;
use Magento\Framework\App\ProductMetadataInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\FileIntegrity\CoreFileIntegrityCheck
 * @covers \IronCart\Scan\Check\FileIntegrity\ManifestRepository
 * @covers \IronCart\Scan\Check\FileIntegrity\Manifest
 */
class CoreFileIntegrityCheckTest extends TestCase
{
    private FilesystemSandbox $webroot;

    private FilesystemSandbox $manifestStore;

    protected function setUp(): void
    {
        $this->webroot = new FilesystemSandbox();
        $this->manifestStore = new FilesystemSandbox();
    }

    protected function tearDown(): void
    {
        $this->webroot->cleanup();
        $this->manifestStore->cleanup();
    }

    public function testCleanWebrootReturnsZeroMismatchFindings(): void
    {
        $files = [
            'app/bootstrap.php' => "<?php\n// magento bootstrap\n",
            'pub/index.php' => "<?php\n// pub index\n",
            'lib/internal/Magento/foo.php' => "<?php\n// lib\n",
        ];
        foreach ($files as $relative => $contents) {
            $this->webroot->writeFile($relative, $contents);
        }
        $this->writeManifest('community', '2.4.7-p5', $this->hashesFor($files));

        $findings = $this->makeCheck('Community', '2.4.7-p5')->run();

        self::assertSame([], $findings, 'clean webroot must produce zero findings');
    }

    public function testMismatchedFileFlaggedHigh(): void
    {
        $files = [
            'app/bootstrap.php' => "<?php\n// magento bootstrap\n",
            'pub/index.php' => "<?php\n// pub index\n",
        ];
        $hashes = $this->hashesFor($files);
        foreach ($files as $relative => $contents) {
            $this->webroot->writeFile($relative, $contents);
        }
        // Tamper with pub/index.php after the manifest is built.
        $this->webroot->writeFile('pub/index.php', "<?php\n// pub index TAMPERED\n");
        $this->writeManifest('community', '2.4.7-p5', $hashes);

        $findings = $this->makeCheck('Community', '2.4.7-p5')->run();

        $highFindings = array_values(array_filter(
            $findings,
            static fn (array $f): bool => $f['severity'] === Severity::HIGH
        ));
        self::assertCount(1, $highFindings, 'expected exactly one HIGH mismatch finding');
        self::assertSame(CoreFileIntegrityCheck::ID, $highFindings[0]['id']);
        self::assertSame('mismatch', $highFindings[0]['evidence']['kind']);
        self::assertSame('pub/index.php', $highFindings[0]['evidence']['file']);
        self::assertSame($hashes['pub/index.php'], $highFindings[0]['evidence']['expected_sha']);
        self::assertNotSame($hashes['pub/index.php'], $highFindings[0]['evidence']['actual_sha']);
        self::assertIsInt($highFindings[0]['evidence']['size_bytes']);

        $summary = $this->findingByKind($findings, 'summary');
        self::assertNotNull($summary);
        self::assertSame(Severity::INFO, $summary['severity']);
        self::assertSame(1, $summary['evidence']['mismatch_count']);
        self::assertSame(0, $summary['evidence']['missing_count']);
        self::assertSame(2, $summary['evidence']['checked_files']);
    }

    public function testMissingFileFlaggedHigh(): void
    {
        $files = [
            'app/bootstrap.php' => "<?php\n",
            'pub/index.php' => "<?php\n",
        ];
        // Only write the first file — second is "missing" from disk.
        $this->webroot->writeFile('app/bootstrap.php', $files['app/bootstrap.php']);
        $this->writeManifest('community', '2.4.7-p5', $this->hashesFor($files));

        $findings = $this->makeCheck('Community', '2.4.7-p5')->run();

        $missing = array_values(array_filter(
            $findings,
            static fn (array $f): bool => is_array($f['evidence']) && ($f['evidence']['kind'] ?? null) === 'missing'
        ));
        self::assertCount(1, $missing);
        self::assertSame(Severity::HIGH, $missing[0]['severity']);
        self::assertSame('pub/index.php', $missing[0]['evidence']['file']);
    }

    public function testExtraFilesInWebrootAreIgnored(): void
    {
        // v2 explicitly does NOT report files-in-webroot-but-not-in-manifest.
        $files = [
            'app/bootstrap.php' => "<?php\n",
        ];
        foreach ($files as $relative => $contents) {
            $this->webroot->writeFile($relative, $contents);
        }
        // Add a marketplace-module file the manifest doesn't know about.
        $this->webroot->writeFile('app/code/Vendor/Module/registration.php', "<?php\n");
        $this->webroot->writeFile('pub/static/frontend/extra.js', '// extra');

        $this->writeManifest('community', '2.4.7-p5', $this->hashesFor($files));

        $findings = $this->makeCheck('Community', '2.4.7-p5')->run();

        self::assertSame([], $findings, 'extra files must NOT be flagged in v2');
    }

    public function testIgnoredPathsInManifestAreSkippedSilently(): void
    {
        $files = [
            'app/bootstrap.php' => "<?php\n",
        ];
        // Manifest entry under an ignored prefix even though the on-disk
        // file differs — must not produce a finding.
        $ignoredEntries = [
            'var/cache/foo' => str_repeat('a', 64),
            'pub/static/frontend/bar' => str_repeat('b', 64),
            'app/etc/env.php' => str_repeat('c', 64),
        ];
        $this->webroot->writeFile('app/bootstrap.php', $files['app/bootstrap.php']);
        $this->webroot->writeFile('var/cache/foo', 'live tampered');
        $this->webroot->writeFile('pub/static/frontend/bar', 'live tampered');
        $this->webroot->writeFile('app/etc/env.php', 'live tampered');

        $this->writeManifest('community', '2.4.7-p5', array_merge($this->hashesFor($files), $ignoredEntries));

        $findings = $this->makeCheck('Community', '2.4.7-p5')->run();

        self::assertSame([], $findings);
    }

    public function testUnsupportedVersionEmitsInformationalFinding(): void
    {
        $findings = $this->makeCheck('Community', '9.9.9-future')->run();

        self::assertCount(1, $findings);
        self::assertSame(CoreFileIntegrityCheck::UNSUPPORTED_ID, $findings[0]['id']);
        self::assertSame(Severity::LOW, $findings[0]['severity']);
        self::assertSame('unsupported_version', $findings[0]['evidence']['reason']);
        self::assertSame('9.9.9-future', $findings[0]['evidence']['version']);
    }

    public function testAdobeCommerceEmitsInformationalFinding(): void
    {
        // Even if a community manifest existed for the same version, an
        // Enterprise edition merchant must receive IC-071, not IC-070.
        $this->writeManifest('community', '2.4.7-p5', ['app/bootstrap.php' => str_repeat('e', 64)]);

        $findings = $this->makeCheck('Enterprise', '2.4.7-p5')->run();

        self::assertCount(1, $findings);
        self::assertSame(CoreFileIntegrityCheck::UNSUPPORTED_ID, $findings[0]['id']);
        self::assertSame('unsupported_edition', $findings[0]['evidence']['reason']);
        self::assertSame(['community'], $findings[0]['evidence']['supported_editions']);
    }

    public function testMassTamperingEmitsSummaryAndCapsDetailedFindings(): void
    {
        $files = [];
        $hashes = [];
        $cap = CoreFileIntegrityCheck::MAX_DETAILED_FINDINGS;
        $tamperedCount = $cap + 5; // 205
        for ($i = 0; $i < $tamperedCount; $i++) {
            $relative = sprintf('app/code/Generated/File%03d.php', $i);
            $original = "<?php\n// original $i\n";
            $hashes[$relative] = hash('sha256', $original);
            // Write a tampered version
            $this->webroot->writeFile($relative, "<?php\n// TAMPERED $i\n");
            $files[$relative] = $original;
        }
        $this->writeManifest('community', '2.4.7-p5', $hashes);

        $findings = $this->makeCheck('Community', '2.4.7-p5')->run();

        $high = array_values(array_filter(
            $findings,
            static fn (array $f): bool => $f['severity'] === Severity::HIGH
        ));
        $mass = $this->findingByKind($findings, 'mass_tampering');

        self::assertCount($cap, $high, 'detailed findings must be capped at MAX_DETAILED_FINDINGS');
        self::assertNotNull($mass, 'mass tampering summary must be emitted above the cap');
        self::assertSame(Severity::CRITICAL, $mass['severity']);
        self::assertSame($tamperedCount, $mass['evidence']['altered_files_total']);
        self::assertSame($cap, $mass['evidence']['detailed_findings_truncated_at']);
    }

    public function testRemediationUrlSet(): void
    {
        $files = ['app/bootstrap.php' => "<?php\n"];
        $hashes = $this->hashesFor($files);
        $this->webroot->writeFile('app/bootstrap.php', "<?php\n// tampered\n");
        $this->writeManifest('community', '2.4.7-p5', $hashes);

        $findings = $this->makeCheck('Community', '2.4.7-p5')->run();

        $mismatch = $this->findingByKind($findings, 'mismatch');
        self::assertNotNull($mismatch);
        self::assertSame(CoreFileIntegrityCheck::REMEDIATION_URL, $mismatch['remediation_url']);
    }

    /**
     * @param array<string,string> $files relative => contents
     * @return array<string,string> relative => sha256 hex
     */
    private function hashesFor(array $files): array
    {
        $out = [];
        foreach ($files as $relative => $contents) {
            $out[$relative] = hash('sha256', $contents);
        }
        return $out;
    }

    /**
     * @param array<string,string> $entries relative => expected_hash
     */
    private function writeManifest(string $edition, string $version, array $entries): void
    {
        $payload = [
            'schema_version' => ManifestRepository::SCHEMA_VERSION,
            'edition' => $edition,
            'version' => $version,
            'source' => 'https://github.com/magento/magento2.git',
            'source_ref' => $version,
            'generated_at' => '2026-05-16',
            'algorithm' => 'sha256',
            'entries' => $entries,
        ];
        $this->manifestStore->writeFile(
            sprintf('magento-core-%s-%s.json', $edition, $version),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function makeCheck(string $edition, string $version): CoreFileIntegrityCheck
    {
        $metadata = $this->createStub(ProductMetadataInterface::class);
        $metadata->method('getVersion')->willReturn($version);
        $metadata->method('getEdition')->willReturn($edition);

        return new CoreFileIntegrityCheck(
            $metadata,
            $this->webroot->magentoRoot(),
            new ManifestRepository($this->manifestStore->root())
        );
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return array<string,mixed>|null
     */
    private function findingByKind(array $findings, string $kind): ?array
    {
        foreach ($findings as $finding) {
            if (!is_array($finding['evidence'] ?? null)) {
                continue;
            }
            if (($finding['evidence']['kind'] ?? null) === $kind) {
                return $finding;
            }
        }
        return null;
    }
}
