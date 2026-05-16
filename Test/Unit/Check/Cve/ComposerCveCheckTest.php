<?php

/**
 * IronCart_Scan — IC-060 ComposerCveCheck unit tests.
 *
 * Exercises the four acceptance-criteria contracts the issue body locks in:
 *
 *   1. Opt-in default is false → emits a single info-level finding pointing
 *      the operator at the admin config switch.
 *   2. URL host-check rejects non-`ironcart.dev` destinations (the
 *      check class hardcodes the URL but we still assert the fake's
 *      enforcement matches the production client).
 *   3. Batching kicks in at 500 packages and chunks into 200-element
 *      requests (one chunk per `post()` call).
 *   4. Transport failure emits exactly one IC-061 LOW finding with the
 *      documented shape.
 *
 * Severity grading from the proxy's CVSS score is also covered — that's
 * what the issue body promises for IC-060's finding output.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Cve;

use IronCart\Scan\Check\Cve\ComposerCveCheck;
use IronCart\Scan\Check\Cve\CurlCveProxyClient;
use IronCart\Scan\Check\Cve\CveProxyClient;
use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\ModuleListInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\Cve\ComposerCveCheck
 */
class ComposerCveCheckTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
    }

    public function testOptInDefaultIsFalseAndReportsDisabledStatus(): void
    {
        $lockPath = $this->writeLock([
            ['name' => 'vendor/pkg', 'version' => '1.0.0'],
        ]);
        $proxy = new FakeCveProxyClient([]);
        $check = $this->makeCheck($lockPath, $proxy, enabled: false);

        $findings = $check->run();

        self::assertCount(1, $findings, 'Disabled state emits exactly one finding.');
        self::assertSame('IC-060', $findings[0]['id']);
        self::assertSame(Severity::INFO, $findings[0]['severity']);
        self::assertSame('disabled', $findings[0]['evidence']['status']);
        self::assertSame(
            ComposerCveCheck::CONFIG_ENABLED,
            $findings[0]['evidence']['config_path']
        );
        self::assertStringContainsString(
            'Stores → Configuration → Ironcart → Scan',
            $findings[0]['evidence']['enable_via']
        );
        self::assertSame(
            [],
            $proxy->calls,
            'When disabled, no outbound call is attempted.'
        );
    }

    public function testHostCheckRejectsEvilCom(): void
    {
        // The production CurlCveProxyClient hard-codes the host check;
        // assert it directly so a misconfigured proxy URL can never
        // exfiltrate the composer manifest to a third party.
        $client = new CurlCveProxyClient();
        $result = $client->post(
            'https://evil.com/api/cve',
            ['schema_version' => '1', 'packages' => []],
            'IronCart-Scan/test (cve-cross-reference)'
        );
        self::assertNull(
            $result,
            'Host check must reject any non-ironcart.dev destination before opening a socket.'
        );
    }

    public function testHostCheckRejectsSubdomainImpersonation(): void
    {
        $client = new CurlCveProxyClient();
        // `ironcart.dev.attacker.com` parses as host = ironcart.dev.attacker.com,
        // NOT ironcart.dev — the strcasecmp must reject it.
        $result = $client->post(
            'https://ironcart.dev.attacker.com/api/cve',
            ['schema_version' => '1', 'packages' => []],
            'IronCart-Scan/test (cve-cross-reference)'
        );
        self::assertNull($result);
    }

    public function testHostCheckRejectsMalformedUrl(): void
    {
        $client = new CurlCveProxyClient();
        self::assertNull($client->post('not a url', [], 'ua'));
        self::assertNull($client->post('', [], 'ua'));
    }

    public function testFakeClientMirrorsProductionHostCheck(): void
    {
        // Belt-and-braces: the FakeCveProxyClient must reject non-allowed
        // hosts too, so unit tests don't get a false positive against a
        // typo in the check's PROXY_URL constant.
        $fake = new FakeCveProxyClient([['findings' => []]]);
        $result = $fake->post(
            'https://evil.com/api/cve',
            ['schema_version' => '1', 'packages' => []],
            'ua'
        );
        self::assertNull($result);
        self::assertSame([], $fake->calls);
    }

    public function testEmitsFallbackOnProxyFailure(): void
    {
        $lockPath = $this->writeLock([
            ['name' => 'vendor/pkg', 'version' => '1.0.0'],
        ]);
        // Empty response queue → fake returns null → IC-061.
        $proxy = new FakeCveProxyClient([null]);
        $check = $this->makeCheck($lockPath, $proxy, enabled: true);

        $findings = $check->run();

        self::assertCount(1, $findings);
        self::assertSame('IC-061', $findings[0]['id']);
        self::assertSame(Severity::LOW, $findings[0]['severity']);
        self::assertSame('OSV cross-reference unavailable', $findings[0]['title']);
        self::assertSame('unavailable', $findings[0]['evidence']['status']);
        self::assertSame(ComposerCveCheck::PROXY_URL, $findings[0]['evidence']['proxy_url']);
        self::assertSame(
            'https://ironcart.dev/docs/checks/IC-061',
            $findings[0]['remediation_url']
        );
    }

    public function testEmitsFallbackOnMissingFindingsArray(): void
    {
        $lockPath = $this->writeLock([
            ['name' => 'vendor/pkg', 'version' => '1.0.0'],
        ]);
        $proxy = new FakeCveProxyClient([['unexpected' => 'shape']]);
        $check = $this->makeCheck($lockPath, $proxy, enabled: true);

        $findings = $check->run();

        self::assertCount(1, $findings);
        self::assertSame('IC-061', $findings[0]['id']);
        self::assertStringContainsString(
            'unexpected response shape',
            $findings[0]['evidence']['reason']
        );
    }

    public function testEmitsFallbackOnMissingComposerLock(): void
    {
        $proxy = new FakeCveProxyClient([]);
        $check = new ComposerCveCheck(
            new ComposerLockReader('/nonexistent/composer.lock'),
            $proxy,
            $this->stubScopeConfig(true),
            $this->stubModuleList('0.2.0')
        );

        $findings = $check->run();

        self::assertCount(1, $findings);
        self::assertSame('IC-061', $findings[0]['id']);
        self::assertStringContainsString('composer.lock unavailable', $findings[0]['evidence']['reason']);
        self::assertSame([], $proxy->calls, 'Lockfile failure must short-circuit before any POST.');
    }

    public function testSinglePostWhenPackageCountUnderThreshold(): void
    {
        $packages = [];
        for ($i = 0; $i < 250; $i++) {
            $packages[] = ['name' => 'vendor/pkg-' . $i, 'version' => '1.0.0'];
        }
        $lockPath = $this->writeLock($packages);

        $proxy = new FakeCveProxyClient([['findings' => []]]);
        $check = $this->makeCheck($lockPath, $proxy, enabled: true);

        $check->run();

        self::assertCount(1, $proxy->calls, 'At 250 packages we send a single batch.');
        self::assertCount(250, $proxy->calls[0]['payload']['packages']);
    }

    public function testBatchesAt500PackagesIntoChunksOf200(): void
    {
        // 501 packages → batching kicks in → ceil(501 / 200) = 3 chunks
        // of (200, 200, 101).
        $packages = [];
        for ($i = 0; $i < 501; $i++) {
            $packages[] = ['name' => 'vendor/pkg-' . $i, 'version' => '1.0.0'];
        }
        $lockPath = $this->writeLock($packages);

        // Three successful empty responses so all batches complete.
        $proxy = new FakeCveProxyClient([
            ['findings' => []],
            ['findings' => []],
            ['findings' => []],
        ]);
        $check = $this->makeCheck($lockPath, $proxy, enabled: true);

        $check->run();

        self::assertCount(3, $proxy->calls, 'Batching at 500 yields three chunks for 501 packages.');
        self::assertCount(200, $proxy->calls[0]['payload']['packages']);
        self::assertCount(200, $proxy->calls[1]['payload']['packages']);
        self::assertCount(101, $proxy->calls[2]['payload']['packages']);
    }

    public function testHappyPathSeverityGradingFromCvssScore(): void
    {
        $lockPath = $this->writeLock([
            ['name' => 'vendor/crit', 'version' => '1.0.0'],
            ['name' => 'vendor/high', 'version' => '1.0.0'],
            ['name' => 'vendor/med',  'version' => '1.0.0'],
            ['name' => 'vendor/low',  'version' => '1.0.0'],
        ]);

        $proxy = new FakeCveProxyClient([[
            'findings' => [
                [
                    'package' => 'vendor/crit',
                    'version' => '1.0.0',
                    'advisory_id' => 'GHSA-aaaa',
                    'severity' => 'critical',
                    'summary' => 'crit',
                    'cvss_score' => 9.8,
                    'remediation_url' => 'https://ironcart.dev/docs/checks/IC-060#GHSA-aaaa',
                ],
                [
                    'package' => 'vendor/high',
                    'version' => '1.0.0',
                    'advisory_id' => 'GHSA-bbbb',
                    'severity' => 'high',
                    'summary' => 'high',
                    'cvss_score' => 7.5,
                    'remediation_url' => '',
                ],
                [
                    'package' => 'vendor/med',
                    'version' => '1.0.0',
                    'advisory_id' => 'GHSA-cccc',
                    'severity' => 'medium',
                    'summary' => 'med',
                    'cvss_score' => 5.0,
                    'remediation_url' => '',
                ],
                [
                    'package' => 'vendor/low',
                    'version' => '1.0.0',
                    'advisory_id' => 'GHSA-dddd',
                    'severity' => 'low',
                    'summary' => 'low',
                    'cvss_score' => 2.1,
                    'remediation_url' => '',
                ],
            ],
        ]]);
        $check = $this->makeCheck($lockPath, $proxy, enabled: true);

        $findings = $check->run();

        self::assertCount(4, $findings);
        $bySeverity = [];
        foreach ($findings as $finding) {
            $bySeverity[$finding['evidence']['package']] = $finding['severity'];
        }
        self::assertSame(Severity::CRITICAL, $bySeverity['vendor/crit']);
        self::assertSame(Severity::HIGH, $bySeverity['vendor/high']);
        self::assertSame(Severity::MEDIUM, $bySeverity['vendor/med']);
        self::assertSame(Severity::LOW, $bySeverity['vendor/low']);
    }

    public function testRemediationUrlFallsBackToCheckDocsAnchor(): void
    {
        $lockPath = $this->writeLock([
            ['name' => 'vendor/pkg', 'version' => '1.0.0'],
        ]);
        $proxy = new FakeCveProxyClient([[
            'findings' => [[
                'package' => 'vendor/pkg',
                'version' => '1.0.0',
                'advisory_id' => 'GHSA-noremediation',
                'severity' => 'high',
                'summary' => '',
                'cvss_score' => 7.5,
                'remediation_url' => '', // empty → check synthesises one
            ]],
        ]]);

        $finding = $this->makeCheck($lockPath, $proxy, enabled: true)->run()[0];

        self::assertSame(
            'https://ironcart.dev/docs/checks/IC-060#GHSA-noremediation',
            $finding['remediation_url']
        );
    }

    public function testDropsMalformedFindingRows(): void
    {
        $lockPath = $this->writeLock([
            ['name' => 'vendor/pkg', 'version' => '1.0.0'],
        ]);
        $proxy = new FakeCveProxyClient([[
            'findings' => [
                'not-an-array',
                ['package' => '', 'advisory_id' => 'GHSA-x'],  // empty package
                ['package' => 'vendor/pkg', 'advisory_id' => ''], // empty advisory
                [
                    'package' => 'vendor/pkg',
                    'version' => '1.0.0',
                    'advisory_id' => 'GHSA-good',
                    'severity' => 'high',
                    'summary' => 'ok',
                    'cvss_score' => 7.5,
                    'remediation_url' => '',
                ],
            ],
        ]]);

        $findings = $this->makeCheck($lockPath, $proxy, enabled: true)->run();

        self::assertCount(1, $findings, 'Only the well-formed row should make it through.');
        self::assertSame('GHSA-good', $findings[0]['evidence']['advisory_id']);
    }

    public function testPayloadHasNoStoreIdentifiers(): void
    {
        $lockPath = $this->writeLock([
            ['name' => 'vendor/pkg', 'version' => '1.0.0'],
        ]);
        $proxy = new FakeCveProxyClient([['findings' => []]]);
        $check = $this->makeCheck($lockPath, $proxy, enabled: true);

        $check->run();

        self::assertCount(1, $proxy->calls);
        $payload = $proxy->calls[0]['payload'];
        // Allowed keys are exactly {schema_version, source, packages}.
        self::assertSame(
            ['schema_version', 'source', 'packages'],
            array_keys($payload),
            'Payload top-level keys must be schema_version / source / packages only.'
        );
        self::assertSame('1', $payload['schema_version']);
        self::assertStringStartsWith('ironcart-magento-scan/', $payload['source']);
        // Each package row is name + version only — no extra metadata.
        foreach ($payload['packages'] as $row) {
            self::assertSame(['name', 'version'], array_keys($row));
        }
        // User-Agent identifies the module, not the merchant.
        self::assertStringStartsWith(
            'IronCart-Scan/',
            $proxy->calls[0]['userAgent']
        );
        self::assertStringContainsString(
            'cve-cross-reference',
            $proxy->calls[0]['userAgent']
        );
    }

    public function testPostUrlPinsToConfiguredProxyEndpoint(): void
    {
        $lockPath = $this->writeLock([
            ['name' => 'vendor/pkg', 'version' => '1.0.0'],
        ]);
        $proxy = new FakeCveProxyClient([['findings' => []]]);
        $check = $this->makeCheck($lockPath, $proxy, enabled: true);

        $check->run();

        self::assertSame(ComposerCveCheck::PROXY_URL, $proxy->calls[0]['url']);
        self::assertSame('https://ironcart.dev/api/cve', $proxy->calls[0]['url']);
        // Sanity-check the host part matches the client's allowlist.
        self::assertSame(
            CveProxyClient::ALLOWED_HOST,
            parse_url($proxy->calls[0]['url'], PHP_URL_HOST)
        );
    }

    /**
     * Write a composer.lock fixture under the system temp dir and queue
     * it for tearDown cleanup.
     *
     * @param list<array{name:string, version:string}> $packages
     */
    private function writeLock(array $packages): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ironcart-cve-test-');
        if ($path === false) {
            self::fail('Unable to create tempfile.');
        }
        $this->tempFiles[] = $path;
        $payload = ['packages' => $packages, 'packages-dev' => []];
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
        return $path;
    }

    private function makeCheck(
        string $lockPath,
        CveProxyClient $proxy,
        bool $enabled
    ): ComposerCveCheck {
        return new ComposerCveCheck(
            new ComposerLockReader($lockPath),
            $proxy,
            $this->stubScopeConfig($enabled),
            $this->stubModuleList('0.2.0')
        );
    }

    private function stubScopeConfig(bool $enabled): ScopeConfigInterface
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('isSetFlag')
            ->willReturnCallback(static function (string $path) use ($enabled): bool {
                return $path === ComposerCveCheck::CONFIG_ENABLED ? $enabled : false;
            });
        return $config;
    }

    private function stubModuleList(string $version): ModuleListInterface
    {
        $moduleList = $this->createMock(ModuleListInterface::class);
        $moduleList->method('getOne')->willReturn([
            'name' => 'IronCart_Scan',
            'setup_version' => $version,
        ]);
        return $moduleList;
    }
}
