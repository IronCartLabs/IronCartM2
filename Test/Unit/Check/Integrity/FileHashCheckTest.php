<?php

/**
 * IronCart_Scan — Recon 7.1 IC-073 diff unit tests.
 *
 * Drives {@see FileHashCheck::diff()} directly with synthetic baseline +
 * current `relative => hash` maps. The diff is the load-bearing algorithm
 * — file walking + persistence are exercised separately ({@see BaselineBuilderTest},
 * integration sandbox) — so this test set stays purely in-memory.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Integrity;

use IronCart\Scan\Check\Filesystem\MagentoRoot;
use IronCart\Scan\Check\Integrity\BaselineBuilder;
use IronCart\Scan\Check\Integrity\BaselineManifest;
use IronCart\Scan\Check\Integrity\BaselineRepository;
use IronCart\Scan\Check\Integrity\FileHashCheck;
use IronCart\Scan\Check\Integrity\IgnorePatterns;
use IronCart\Scan\Check\License\LicenseConfig;
use IronCart\Scan\Report\Severity;
use IronCart\Scan\Test\Unit\Check\Filesystem\FilesystemSandbox;
use Magento\Framework\App\Filesystem\DirectoryList;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\Integrity\FileHashCheck
 * @covers \IronCart\Scan\Check\Integrity\BaselineManifest
 */
class FileHashCheckTest extends TestCase
{
    public function testCleanSnapshotMatchesBaselineProducesZeroFindings(): void
    {
        $entries = [
            'app/code/Vendor/Mod/etc/module.xml' => str_repeat('a', 64),
            'app/etc/di.xml' => str_repeat('b', 64),
            'vendor/magento/framework/App.php' => str_repeat('c', 64),
        ];

        $check = $this->makeCheck();
        $baseline = $this->baseline($entries);

        $findings = $check->diff($entries, $entries, $baseline);

        self::assertSame([], $findings);
    }

    public function testModifiedFileUnderAppCodeIsHigh(): void
    {
        $hashA = str_repeat('a', 64);
        $hashAA = str_repeat('1', 64);
        $base = ['app/code/Vendor/Mod/Backdoor.php' => $hashA];
        $current = ['app/code/Vendor/Mod/Backdoor.php' => $hashAA];

        $findings = $this->makeCheck()->diff($base, $current, $this->baseline($base));

        $high = $this->severityOf($findings, Severity::HIGH);
        self::assertCount(1, $high);
        self::assertSame('modified', $high[0]['evidence']['kind']);
        self::assertSame('app/code/Vendor/Mod/Backdoor.php', $high[0]['evidence']['file']);
        self::assertSame($hashA, $high[0]['evidence']['expected_sha']);
        self::assertSame($hashAA, $high[0]['evidence']['actual_sha']);
    }

    public function testModifiedFileUnderVendorMagentoIsMedium(): void
    {
        $base = ['vendor/magento/framework/Foo.php' => str_repeat('a', 64)];
        $current = ['vendor/magento/framework/Foo.php' => str_repeat('b', 64)];

        $findings = $this->makeCheck()->diff($base, $current, $this->baseline($base));

        $medium = $this->severityOf($findings, Severity::MEDIUM);
        self::assertCount(1, $medium);
        self::assertSame('modified', $medium[0]['evidence']['kind']);
        self::assertSame(FileHashCheck::ID, $medium[0]['id']);
    }

    public function testDeletedFileEmitsFindingWithNullActualHash(): void
    {
        $base = ['app/etc/config.dist.php' => str_repeat('a', 64)];

        $findings = $this->makeCheck()->diff($base, [], $this->baseline($base));

        $detail = array_values(array_filter(
            $findings,
            static fn (array $f): bool => is_array($f['evidence']) && ($f['evidence']['kind'] ?? null) === 'deleted'
        ));
        self::assertCount(1, $detail);
        self::assertSame(Severity::HIGH, $detail[0]['severity']);
        self::assertNull($detail[0]['evidence']['actual_sha']);
        self::assertSame(str_repeat('a', 64), $detail[0]['evidence']['expected_sha']);
    }

    public function testNewFileEmitsFindingWithNullExpectedHash(): void
    {
        $current = ['app/code/Evil/InjectedAdmin/etc/db_schema.xml' => str_repeat('a', 64)];

        $findings = $this->makeCheck()->diff([], $current, $this->baseline([]));

        $detail = array_values(array_filter(
            $findings,
            static fn (array $f): bool => is_array($f['evidence']) && ($f['evidence']['kind'] ?? null) === 'new'
        ));
        self::assertCount(1, $detail);
        self::assertSame(Severity::HIGH, $detail[0]['severity']);
        self::assertNull($detail[0]['evidence']['expected_sha']);
    }

    public function testSummaryAppendedWhenAnyDriftIsDetected(): void
    {
        $base = [
            'app/code/A.php' => str_repeat('a', 64),
            'app/code/B.php' => str_repeat('b', 64),
        ];
        $current = [
            'app/code/A.php' => str_repeat('a', 64),
            'app/code/B.php' => str_repeat('Z', 64),
        ];

        $findings = $this->makeCheck()->diff($base, $current, $this->baseline($base));

        $summary = $this->findByKind($findings, 'summary');
        self::assertNotNull($summary);
        self::assertSame(Severity::INFO, $summary['severity']);
        self::assertSame(1, $summary['evidence']['altered_files_total']);
        self::assertSame(1, $summary['evidence']['modified_count']);
        self::assertSame(0, $summary['evidence']['missing_count']);
        self::assertSame(0, $summary['evidence']['new_count']);
        self::assertSame(2, $summary['evidence']['baseline_entry_count']);
        self::assertSame(2, $summary['evidence']['current_entry_count']);
    }

    public function testMassTamperingSummaryTakesOverAboveThreshold(): void
    {
        $base = [];
        $current = [];
        $limit = FileHashCheck::MAX_DETAILED_FINDINGS;
        for ($i = 0; $i < $limit + 5; $i++) {
            $relative = sprintf('app/code/Vendor/Mod/File%04d.php', $i);
            $base[$relative] = str_repeat('a', 64);
            $current[$relative] = str_repeat('z', 64);
        }

        $findings = $this->makeCheck()->diff($base, $current, $this->baseline($base));

        $mass = $this->findByKind($findings, 'mass_tampering');
        self::assertNotNull($mass);
        self::assertSame(Severity::CRITICAL, $mass['severity']);
        self::assertSame($limit + 5, $mass['evidence']['altered_files_total']);
        // No summary INFO finding when we've already escalated to mass-tampering.
        self::assertNull($this->findByKind($findings, 'summary'));
        // Detailed findings capped at the threshold (+ the mass-tampering summary itself).
        self::assertLessThanOrEqual($limit + 1, count($findings));
    }

    public function testFreeTierShortCircuitsWithoutFilesystemAccess(): void
    {
        // A LicenseConfig stub whose parsedClaims() returns null. We pass
        // throwaway dependencies for the rest — the check must NOT touch
        // them when the license gate fails.
        $check = new FileHashCheck(
            magentoRoot: $this->magentoRoot(),
            baselineRepository: $this->throwingBaselineRepository(),
            ignorePatterns: IgnorePatterns::fromLists([], []),
            baselineBuilder: $this->throwingBaselineBuilder(),
            licenseConfig: $this->licenseConfigStub(null)
        );

        self::assertSame([], $check->run());
    }

    public function testNoBaselineEmitsSingleLowInformationalFinding(): void
    {
        $sandbox = new FilesystemSandbox();
        try {
            $root = $sandbox->magentoRoot();
            $repo = new BaselineRepository($root);
            $check = new FileHashCheck(
                magentoRoot: $root,
                baselineRepository: $repo,
                ignorePatterns: IgnorePatterns::fromLists([], []),
                baselineBuilder: new BaselineBuilder($root, IgnorePatterns::fromLists([], [])),
                licenseConfig: $this->licenseConfigStub(['accountId' => 'acct', 'sku' => 'recon'])
            );

            $findings = $check->run();

            self::assertCount(1, $findings);
            self::assertSame(FileHashCheck::NO_BASELINE_ID, $findings[0]['id']);
            self::assertSame(Severity::LOW, $findings[0]['severity']);
            self::assertSame('no_baseline', $findings[0]['evidence']['kind']);
            self::assertStringContainsString('var/recon/baseline.json', (string) $findings[0]['evidence']['baseline_path']);
        } finally {
            $sandbox->cleanup();
        }
    }

    /**
     * @return list<array{id:string,title:string,severity:string,evidence:array<string,mixed>,remediation_url:string}>
     */
    private function severityOf(array $findings, string $severity): array
    {
        return array_values(array_filter(
            $findings,
            static fn (array $f): bool => $f['severity'] === $severity
        ));
    }

    /**
     * @return array{id:string,title:string,severity:string,evidence:array<string,mixed>,remediation_url:string}|null
     */
    private function findByKind(array $findings, string $kind): ?array
    {
        foreach ($findings as $f) {
            if (is_array($f['evidence']) && ($f['evidence']['kind'] ?? null) === $kind) {
                return $f;
            }
        }
        return null;
    }

    /**
     * @param array<string,string> $entries
     */
    private function baseline(array $entries): BaselineManifest
    {
        return new BaselineManifest(
            generatedAt: '2026-05-19T00:00:00Z',
            magentoEdition: 'Community',
            magentoVersion: '2.4.7-p5',
            algorithm: BaselineManifest::ALGORITHM_SHA256,
            roots: BaselineBuilder::DEFAULT_ROOTS,
            entries: $entries
        );
    }

    private function makeCheck(): FileHashCheck
    {
        return new FileHashCheck(
            magentoRoot: $this->magentoRoot(),
            baselineRepository: $this->throwingBaselineRepository(),
            ignorePatterns: IgnorePatterns::fromLists([], []),
            baselineBuilder: $this->throwingBaselineBuilder(),
            licenseConfig: $this->licenseConfigStub(['accountId' => 'acct', 'sku' => 'recon'])
        );
    }

    private function magentoRoot(): MagentoRoot
    {
        $directoryList = new class extends DirectoryList {
            public function __construct()
            {
                // Intentionally skip parent constructor — tests only need getRoot().
            }

            public function getRoot(): string
            {
                return '/srv/magento';
            }
        };
        return new MagentoRoot($directoryList);
    }

    private function throwingBaselineRepository(): BaselineRepository
    {
        // Anonymous subclass — diff() never touches it, so any unexpected
        // method call would throw and fail the test loudly.
        return new class ($this->magentoRoot()) extends BaselineRepository {
            public function load(): ?BaselineManifest
            {
                throw new \LogicException('diff() must not load baseline');
            }

            public function save(BaselineManifest $manifest): void
            {
                throw new \LogicException('diff() must not save baseline');
            }
        };
    }

    private function throwingBaselineBuilder(): BaselineBuilder
    {
        return new class ($this->magentoRoot(), IgnorePatterns::fromLists([], [])) extends BaselineBuilder {
            public function build(string $magentoEdition, string $magentoVersion): BaselineManifest
            {
                throw new \LogicException('diff() must not walk filesystem');
            }
        };
    }

    /**
     * @param array<string,mixed>|null $claims
     */
    private function licenseConfigStub(?array $claims): LicenseConfig
    {
        return new class ($claims) extends LicenseConfig {
            public function __construct(private readonly ?array $stubClaims)
            {
                // Intentionally skip parent constructor — tests only call parsedClaims().
            }

            public function parsedClaims(): ?array
            {
                /** @var null|array{accountId:string,sku:string,issuedAt:int,expiresAt:int,nonce:string,sigVersion:int} */
                return $this->stubClaims;
            }
        };
    }
}
