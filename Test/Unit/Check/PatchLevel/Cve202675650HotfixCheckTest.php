<?php

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\PatchLevel;

use IronCart\Scan\Check\PatchLevel\Cve202675650HotfixCheck;
use IronCart\Scan\Report\Severity;
use IronCart\Scan\Test\Unit\Check\Filesystem\FilesystemSandbox;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\PatchLevel\Cve202675650HotfixCheck
 */
class Cve202675650HotfixCheckTest extends TestCase
{
    /**
     * Fixture basename => install path relative to the Magento root.
     */
    private const TARGETS = [
        'BlockFactory.php.txt' => 'vendor/magento/framework/View/Element/BlockFactory.php',
        'UrlGeneratorFactory.php.txt' => 'vendor/magento/module-backend/Model/Widget/Grid/Row/UrlGeneratorFactory.php',
        'ErrorProcessor.php.txt' => 'vendor/magento/framework/Webapi/ErrorProcessor.php',
        'processor.php.txt' => 'pub/errors/processor.php',
    ];

    private FilesystemSandbox $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = new FilesystemSandbox();
    }

    protected function tearDown(): void
    {
        $this->sandbox->cleanup();
    }

    public function testFullyPatchedStoreReturnsNoFindings(): void
    {
        $this->install(array_fill_keys(array_keys(self::TARGETS), 'patched'));

        self::assertSame([], $this->runCheck());
    }

    public function testUnpatchedStoreIsCritical(): void
    {
        $this->install(array_fill_keys(array_keys(self::TARGETS), 'vulnerable'));

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Cve202675650HotfixCheck::ID, $findings[0]['id']);
        self::assertSame('IC-003', $findings[0]['id']);
        self::assertSame(Severity::CRITICAL, $findings[0]['severity']);
        self::assertSame('https://ironcart.dev/docs/checks/IC-003', $findings[0]['remediation_url']);
        self::assertSame(
            array_fill_keys(array_values(self::TARGETS), Cve202675650HotfixCheck::STATE_VULNERABLE),
            $findings[0]['evidence']['files']
        );
    }

    public function testRevertedErrorProcessorAloneIsCritical(): void
    {
        // e.g. a later `composer install` redeployed pub/errors/ over the hotfix.
        $variants = array_fill_keys(array_keys(self::TARGETS), 'patched');
        $variants['processor.php.txt'] = 'vulnerable';
        $this->install($variants);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Severity::CRITICAL, $findings[0]['severity']);
        self::assertSame(
            Cve202675650HotfixCheck::STATE_VULNERABLE,
            $findings[0]['evidence']['files']['pub/errors/processor.php']
        );
        self::assertSame(
            Cve202675650HotfixCheck::STATE_PATCHED,
            $findings[0]['evidence']['files']['vendor/magento/framework/View/Element/BlockFactory.php']
        );
    }

    public function testVulnerableWinsOverUnknown(): void
    {
        $this->install(['BlockFactory.php.txt' => 'vulnerable']);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Severity::CRITICAL, $findings[0]['severity']);
    }

    public function testNonVendorLayoutIsInfo(): void
    {
        // app/code + lib/internal (git-clone) install: no vendor/magento tree.
        $this->sandbox->makeDir('app/code/Magento');

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Severity::INFO, $findings[0]['severity']);
        self::assertSame(
            array_fill_keys(array_values(self::TARGETS), Cve202675650HotfixCheck::STATE_UNKNOWN),
            $findings[0]['evidence']['files']
        );
        self::assertArrayHasKey('explanation', $findings[0]['evidence']);
    }

    public function testUnrecognisedFileContentIsInfo(): void
    {
        $this->install(array_fill_keys(array_keys(self::TARGETS), 'patched'));
        $this->sandbox->writeFile(
            self::TARGETS['BlockFactory.php.txt'],
            "<?php\n// customised core file matching neither signature\n"
        );

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Severity::INFO, $findings[0]['severity']);
        self::assertSame(
            Cve202675650HotfixCheck::STATE_UNKNOWN,
            $findings[0]['evidence']['files']['vendor/magento/framework/View/Element/BlockFactory.php']
        );
    }

    public function testFileCarryingBothMarkersIsUnknownNotPatched(): void
    {
        $this->install(array_fill_keys(array_keys(self::TARGETS), 'patched'));
        $both = $this->fixture('vulnerable', 'BlockFactory.php.txt')
            . $this->fixture('patched', 'BlockFactory.php.txt');
        $this->sandbox->writeFile(self::TARGETS['BlockFactory.php.txt'], $both);

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Severity::INFO, $findings[0]['severity']);
    }

    public function testRemediationNamesHotfixAndKeyRotationWithoutClaimingToVerifyIt(): void
    {
        $this->install(array_fill_keys(array_keys(self::TARGETS), 'vulnerable'));

        $evidence = $this->runCheck()[0]['evidence'];

        self::assertSame('CVE-2026-75650', $evidence['cve']);
        self::assertSame('VULN-39341', $evidence['hotfix']);
        self::assertFalse($evidence['encryption_key_rotation_verified']);
        self::assertStringContainsString('VULN-39341', $evidence['remediation']);
        self::assertStringContainsString('commerce-apsb26-146', $evidence['remediation']);
        self::assertStringContainsString('encryption key', $evidence['remediation']);
        self::assertStringContainsString('does NOT verify', $evidence['remediation']);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function runCheck(): array
    {
        return (new Cve202675650HotfixCheck($this->sandbox->magentoRoot()))->run();
    }

    /**
     * @param array<string,string> $variants fixture basename => `patched` | `vulnerable`
     */
    private function install(array $variants): void
    {
        foreach ($variants as $fixture => $variant) {
            $this->sandbox->writeFile(self::TARGETS[$fixture], $this->fixture($variant, $fixture));
        }
    }

    private function fixture(string $variant, string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/_files/cve-2026-75650/' . $variant . '/' . $name);
        self::assertIsString($contents);

        return $contents;
    }
}
