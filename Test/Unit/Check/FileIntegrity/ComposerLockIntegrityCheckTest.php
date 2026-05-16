<?php

/**
 * IronCart_Scan — IC-072 unit tests.
 *
 * Exercises the clean-match, shasum-mismatch, missing-manifest, and
 * third-party-silently-skipped code paths against synthetic composer.lock +
 * manifest fixtures in a tmpdir sandbox. The fixtures are intentionally tiny
 * — IC-072's real performance characteristics are validated by the
 * integration cell in CI.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\FileIntegrity;

use IronCart\Scan\Check\FileIntegrity\ComposerLockIntegrityCheck;
use IronCart\Scan\Check\FileIntegrity\ComposerLockManifestRepository;
use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\ProductMetadataInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\FileIntegrity\ComposerLockIntegrityCheck
 * @covers \IronCart\Scan\Check\FileIntegrity\ComposerLockManifest
 * @covers \IronCart\Scan\Check\FileIntegrity\ComposerLockManifestRepository
 */
class ComposerLockIntegrityCheckTest extends TestCase
{
    /**
     * @var list<string> Paths of temp files created during the test for cleanup
     */
    private array $tempFiles = [];

    /**
     * @var list<string> Paths of temp dirs created during the test for cleanup
     */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempFiles = [];
        $this->tempDirs = [];
    }

    public function testCleanLockReturnsZeroFindings(): void
    {
        $shaA = str_repeat('a', 40);
        $shaB = str_repeat('b', 40);
        $lockPath = $this->writeLock([
            ['name' => 'magento/framework',       'version' => '103.0.0', 'dist' => ['shasum' => $shaA]],
            ['name' => 'magento/module-catalog',  'version' => '104.0.0', 'dist' => ['shasum' => $shaB]],
        ]);
        $manifestDir = $this->writeManifest('community', '2.4.7-p5', [
            'magento/framework'       => $shaA,
            'magento/module-catalog'  => $shaB,
        ]);

        $findings = $this->makeCheck('Community', '2.4.7-p5', $lockPath, $manifestDir)->run();

        self::assertSame([], $findings, 'clean composer.lock must produce zero findings');
    }

    public function testMismatchedShasumFlaggedHigh(): void
    {
        $shaExpected = str_repeat('a', 40);
        $shaActual = str_repeat('9', 40);
        $lockPath = $this->writeLock([
            ['name' => 'magento/framework', 'version' => '103.0.0', 'dist' => ['shasum' => $shaActual]],
        ]);
        $manifestDir = $this->writeManifest('community', '2.4.7-p5', [
            'magento/framework' => $shaExpected,
        ]);

        $findings = $this->makeCheck('Community', '2.4.7-p5', $lockPath, $manifestDir)->run();

        self::assertCount(1, $findings);
        self::assertSame(ComposerLockIntegrityCheck::ID, $findings[0]['id']);
        self::assertSame(Severity::HIGH, $findings[0]['severity']);
        self::assertSame('magento/framework', $findings[0]['evidence']['package']);
        self::assertSame('103.0.0', $findings[0]['evidence']['version']);
        self::assertSame($shaExpected, $findings[0]['evidence']['expected_sha1']);
        self::assertSame($shaActual, $findings[0]['evidence']['actual_sha1']);
        self::assertSame(ComposerLockIntegrityCheck::REMEDIATION_URL, $findings[0]['remediation_url']);
    }

    public function testMissingManifestEmitsSingleInformationalFinding(): void
    {
        // composer.lock with one mismatched package — but no manifest exists
        // for the merchant's version, so the result must be exactly one
        // IC-073 finding (not a HIGH IC-072 finding).
        $lockPath = $this->writeLock([
            ['name' => 'magento/framework', 'version' => '103.0.0', 'dist' => ['shasum' => str_repeat('9', 40)]],
        ]);
        $manifestDir = $this->mkTmpDir(); // empty manifests dir

        $findings = $this->makeCheck('Community', '9.9.9-future', $lockPath, $manifestDir)->run();

        self::assertCount(1, $findings);
        self::assertSame(ComposerLockIntegrityCheck::UNSUPPORTED_ID, $findings[0]['id']);
        self::assertSame(Severity::LOW, $findings[0]['severity']);
        self::assertSame('unsupported_version', $findings[0]['evidence']['reason']);
        self::assertSame('9.9.9-future', $findings[0]['evidence']['version']);
        self::assertSame(['community'], $findings[0]['evidence']['supported_editions']);
        self::assertSame(
            ComposerLockIntegrityCheck::UNSUPPORTED_REMEDIATION_URL,
            $findings[0]['remediation_url']
        );
    }

    public function testAdobeCommerceEmitsUnsupportedEdition(): void
    {
        $shaA = str_repeat('a', 40);
        $lockPath = $this->writeLock([
            ['name' => 'magento/framework', 'version' => '103.0.0', 'dist' => ['shasum' => $shaA]],
        ]);
        // A community manifest exists for the same version, but Enterprise
        // merchants must receive IC-073, not IC-072.
        $manifestDir = $this->writeManifest('community', '2.4.7-p5', [
            'magento/framework' => $shaA,
        ]);

        $findings = $this->makeCheck('Enterprise', '2.4.7-p5', $lockPath, $manifestDir)->run();

        self::assertCount(1, $findings);
        self::assertSame(ComposerLockIntegrityCheck::UNSUPPORTED_ID, $findings[0]['id']);
        self::assertSame('unsupported_edition', $findings[0]['evidence']['reason']);
    }

    public function testThirdPartyPackagesAreSilentlyIgnored(): void
    {
        $magentoSha = str_repeat('a', 40);
        // The third-party package's shasum value is irrelevant — it must
        // never be checked because the package is not in the manifest.
        $thirdPartySha = str_repeat('9', 40);
        $lockPath = $this->writeLock([
            ['name' => 'magento/framework',         'version' => '103.0.0', 'dist' => ['shasum' => $magentoSha]],
            ['name' => 'amasty/module-base',        'version' => '1.2.3',   'dist' => ['shasum' => $thirdPartySha]],
            ['name' => 'mageplaza/blog',            'version' => '4.5.6',   'dist' => ['shasum' => $thirdPartySha]],
        ]);
        // Manifest only knows about the magento/* package.
        $manifestDir = $this->writeManifest('community', '2.4.7-p5', [
            'magento/framework' => $magentoSha,
        ]);

        $findings = $this->makeCheck('Community', '2.4.7-p5', $lockPath, $manifestDir)->run();

        self::assertSame([], $findings, 'third-party packages must be silently ignored');
    }

    public function testPackagesWithoutDistAreSkippedSilently(): void
    {
        // Path/composer-plugin entries can lack a `dist` block. The reader
        // should omit them and the check should not blow up.
        $magentoSha = str_repeat('a', 40);
        $lockPath = $this->writeLock([
            ['name' => 'magento/framework', 'version' => '103.0.0', 'dist' => ['shasum' => $magentoSha]],
            // No `dist` block at all — `composer.lock` from path repositories
            ['name' => 'merchant/local-fork', 'version' => 'dev-main'],
        ]);
        $manifestDir = $this->writeManifest('community', '2.4.7-p5', [
            'magento/framework' => $magentoSha,
        ]);

        $findings = $this->makeCheck('Community', '2.4.7-p5', $lockPath, $manifestDir)->run();

        self::assertSame([], $findings);
    }

    public function testMultipleMismatchesEmitMultipleFindings(): void
    {
        $expectedA = str_repeat('a', 40);
        $expectedB = str_repeat('b', 40);
        $expectedC = str_repeat('c', 40);
        $tamperA   = str_repeat('1', 40);
        $tamperB   = str_repeat('2', 40);
        // Two of the three packages have a tampered shasum.
        $lockPath = $this->writeLock([
            ['name' => 'magento/framework',         'version' => '103.0.0', 'dist' => ['shasum' => $tamperA]],
            ['name' => 'magento/module-catalog',    'version' => '104.0.0', 'dist' => ['shasum' => $tamperB]],
            ['name' => 'magento/module-checkout',   'version' => '100.4.0', 'dist' => ['shasum' => $expectedC]],
        ]);
        $manifestDir = $this->writeManifest('community', '2.4.7-p5', [
            'magento/framework'       => $expectedA,
            'magento/module-catalog'  => $expectedB,
            'magento/module-checkout' => $expectedC,
        ]);

        $findings = $this->makeCheck('Community', '2.4.7-p5', $lockPath, $manifestDir)->run();

        self::assertCount(2, $findings);
        $packages = array_map(static fn (array $f): string => $f['evidence']['package'], $findings);
        sort($packages);
        self::assertSame(['magento/framework', 'magento/module-catalog'], $packages);
        foreach ($findings as $finding) {
            self::assertSame(ComposerLockIntegrityCheck::ID, $finding['id']);
            self::assertSame(Severity::HIGH, $finding['severity']);
        }
    }

    public function testUnreadableLockfileDegradesSilently(): void
    {
        // composer.lock missing entirely. IC-002 already reports this case;
        // IC-072 must NOT double-report.
        $manifestDir = $this->writeManifest('community', '2.4.7-p5', [
            'magento/framework' => str_repeat('a', 40),
        ]);

        $missingLock = sys_get_temp_dir() . '/ironcart-no-such-lock-' . bin2hex(random_bytes(4)) . '.json';

        $findings = $this->makeCheck('Community', '2.4.7-p5', $missingLock, $manifestDir)->run();

        self::assertSame([], $findings);
    }

    public function testEmptyVersionStringEmitsUnsupportedFinding(): void
    {
        $lockPath = $this->writeLock([]);
        $manifestDir = $this->mkTmpDir();

        $findings = $this->makeCheck('Community', '', $lockPath, $manifestDir)->run();

        self::assertCount(1, $findings);
        self::assertSame(ComposerLockIntegrityCheck::UNSUPPORTED_ID, $findings[0]['id']);
        self::assertSame('(unknown version)', substr($findings[0]['title'], -strlen('(unknown version)')));
    }

    // ----------------------------------------------------------------------
    // Fixture helpers
    // ----------------------------------------------------------------------

    /**
     * @param list<array<string,mixed>> $packages
     */
    private function writeLock(array $packages): string
    {
        $path = sys_get_temp_dir() . '/ironcart-composer-lock-' . bin2hex(random_bytes(6)) . '.json';
        $payload = [
            'packages' => $packages,
            'packages-dev' => [],
        ];
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->tempFiles[] = $path;
        return $path;
    }

    /**
     * @param array<string,string> $entries
     */
    private function writeManifest(string $edition, string $version, array $entries): string
    {
        $dir = $this->mkTmpDir();
        $payload = [
            'schema_version' => ComposerLockManifestRepository::SCHEMA_VERSION,
            'edition' => $edition,
            'version' => $version,
            'source' => 'composer create-project magento/project-community-edition',
            'source_ref' => $version,
            'generated_at' => '2026-05-16',
            'algorithm' => ComposerLockManifestRepository::ALGORITHM,
            'entries' => $entries,
        ];
        $filename = sprintf('composer-sha1-%s-%s.json', $edition, $version);
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . $filename,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        return $dir;
    }

    private function mkTmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/ironcart-composer-manifest-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    private function makeCheck(
        string $edition,
        string $version,
        string $lockPath,
        string $manifestDir
    ): ComposerLockIntegrityCheck {
        $metadata = $this->createStub(ProductMetadataInterface::class);
        $metadata->method('getVersion')->willReturn($version);
        $metadata->method('getEdition')->willReturn($edition);

        return new ComposerLockIntegrityCheck(
            $metadata,
            new ComposerLockReader($lockPath),
            new ComposerLockManifestRepository($manifestDir)
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
