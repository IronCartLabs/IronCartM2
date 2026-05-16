<?php

/**
 * IronCart_Scan — UploadRunner unit tests.
 *
 * Covers the critical invariants documented on IronCartLabs/IronCartM2#57:
 *
 *   - Upload disabled → exit 0, never invokes HTTP client
 *   - Upload enabled, no token → non-zero exit, never invokes HTTP client
 *   - Host mismatch is enforced
 *   - Payload size guard (> 500 findings, > 1000 packages) → rejection
 *   - Payload JSON has no `admin_email` / `operator_email` keys anywhere
 *   - 2xx → prints `view_url`
 *   - 401 → non-zero exit, never leaks response body
 *   - 5xx → retry behavior covered indirectly via FakeUploadClient queue
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Upload;

use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use IronCart\Scan\Check\Upload\UploadClient;
use IronCart\Scan\Check\Upload\UploadClientResult;
use IronCart\Scan\Check\Upload\UploadConfig;
use IronCart\Scan\Check\Upload\UploadPayloadBuilder;
use IronCart\Scan\Check\Upload\UploadRunner;
use IronCart\Scan\Check\Upload\UploadRunnerOutcome;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\Upload\UploadRunner
 * @covers \IronCart\Scan\Check\Upload\UploadPayloadBuilder
 * @covers \IronCart\Scan\Check\Upload\UploadConfig
 */
class UploadRunnerTest extends TestCase
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

    public function testDisabledExitsZeroAndNeverInvokesClient(): void
    {
        $client = new FakeUploadClient();
        $runner = $this->makeRunner(enabled: false, token: '', client: $client);

        $outcome = $runner->run([]);

        self::assertSame(UploadRunnerOutcome::EXIT_OK, $outcome->exitCode);
        self::assertSame([], $client->invocations, 'Disabled flow must never open a socket');
        self::assertStringContainsString('Upload disabled', $outcome->stdout);
    }

    public function testEnabledWithoutTokenExitsNonZeroAndNeverInvokesClient(): void
    {
        $client = new FakeUploadClient();
        $runner = $this->makeRunner(enabled: true, token: '', client: $client);

        $outcome = $runner->run([]);

        self::assertNotSame(UploadRunnerOutcome::EXIT_OK, $outcome->exitCode);
        self::assertSame(UploadRunnerOutcome::EXIT_MISCONFIGURED, $outcome->exitCode);
        self::assertSame([], $client->invocations, 'Missing token must never open a socket');
        self::assertStringContainsString('no token configured', $outcome->stderr);
    }

    public function testHostMismatchIsRejected(): void
    {
        // Mock the encryptor to return the same string (no real encryption
        // in unit tests) and override the endpoint via config to point at
        // a non-allowlisted host. The fake client mirrors the production
        // host check.
        $client = new FakeUploadClient();
        $runner = $this->makeRunner(
            enabled: true,
            token: 'secret-token',
            client: $client,
            endpoint: 'https://evil.com/api/scan/ingest',
            allowedHost: 'ironcart.dev'
        );

        $outcome = $runner->run([]);

        self::assertSame(UploadRunnerOutcome::EXIT_MISCONFIGURED, $outcome->exitCode);
        self::assertStringContainsString('allow-list', $outcome->stderr);
    }

    public function testTooManyFindingsIsRejectedBeforeSocket(): void
    {
        $client = new FakeUploadClient();
        $runner = $this->makeRunner(enabled: true, token: 'tok', client: $client);

        $tooMany = array_fill(0, UploadPayloadBuilder::MAX_FINDINGS + 1, [
            'id' => 'IC-001',
            'title' => 'placeholder',
            'severity' => Severity::LOW,
            'evidence' => ['n' => 1],
            'remediation_url' => 'https://ironcart.dev/docs/checks/IC-001',
        ]);

        $outcome = $runner->run($tooMany);

        self::assertSame(UploadRunnerOutcome::EXIT_MISCONFIGURED, $outcome->exitCode);
        self::assertSame([], $client->invocations);
        self::assertStringContainsString('exceed', $outcome->stderr);
    }

    public function testTooManyComposerPackagesIsRejectedBeforeSocket(): void
    {
        // Synthesize a composer.lock with > 1000 packages.
        $packages = [];
        for ($i = 0; $i <= UploadPayloadBuilder::MAX_COMPOSER_PACKAGES; $i++) {
            $packages[] = ['name' => 'vendor/pkg-' . $i, 'version' => '1.0.0'];
        }
        $lockPath = $this->writeJson(['packages' => $packages]);

        $client = new FakeUploadClient();
        $runner = $this->makeRunner(
            enabled: true,
            token: 'tok',
            client: $client,
            composerLock: $lockPath
        );

        $outcome = $runner->run([]);

        self::assertSame(UploadRunnerOutcome::EXIT_MISCONFIGURED, $outcome->exitCode);
        self::assertSame([], $client->invocations);
        self::assertStringContainsString('package', $outcome->stderr);
    }

    public function testPayloadHasNoAdminEmailOrOperatorEmailAnywhere(): void
    {
        // Even when a finding's evidence contains a key called
        // `admin_email`, the runner must refuse to upload. This is the
        // hard PII invariant.
        $client = new FakeUploadClient();
        $runner = $this->makeRunner(enabled: true, token: 'tok', client: $client);

        $maliciousFindings = [[
            'id' => 'IC-011',
            'title' => 'Stale admin account',
            'severity' => Severity::MEDIUM,
            'evidence' => [
                'username_hash' => 'abc',
                // Hostile key — pretend a buggy future check tried to
                // include the admin's email in evidence. The runner MUST
                // refuse to upload.
                'admin_email' => 'kristian@example.com',
            ],
            'remediation_url' => 'https://ironcart.dev/docs/checks/IC-011',
        ]];

        $outcome = $runner->run($maliciousFindings);

        self::assertSame(UploadRunnerOutcome::EXIT_MISCONFIGURED, $outcome->exitCode);
        self::assertSame([], $client->invocations, 'Forbidden PII key must abort before socket open');
        self::assertStringContainsString('forbidden PII key', $outcome->stderr);
    }

    public function testSerializedPayloadContainsNoEmailKey(): void
    {
        // Happy path: scan with a normal finding. After build(), serialise
        // the JSON and assert the string never contains `admin_email`.
        $client = new FakeUploadClient();
        $runner = $this->makeRunner(enabled: true, token: 'tok', client: $client);

        $outcome = $runner->run([[
            'id' => 'IC-020',
            'title' => 'mage mode',
            'severity' => Severity::CRITICAL,
            'evidence' => ['mage_mode' => 'developer'],
            'remediation_url' => 'https://ironcart.dev/docs/checks/IC-020',
        ]]);

        self::assertSame(UploadRunnerOutcome::EXIT_OK, $outcome->exitCode);
        self::assertCount(1, $client->invocations);

        $serialised = json_encode($client->invocations[0]['payload'], JSON_THROW_ON_ERROR);
        self::assertIsString($serialised);
        self::assertStringNotContainsString('admin_email', $serialised);
        self::assertStringNotContainsString('operator_email', $serialised);
        self::assertStringNotContainsString('admin_username', $serialised);
    }

    public function testHappyPathPrintsViewUrl(): void
    {
        $client = new FakeUploadClient();
        $client->queuedResponses = [
            new UploadClientResult(200, 'https://ironcart.dev/scan/xyz', UploadClientResult::CATEGORY_OK),
        ];
        $runner = $this->makeRunner(enabled: true, token: 'tok', client: $client);

        $outcome = $runner->run([]);

        self::assertSame(UploadRunnerOutcome::EXIT_OK, $outcome->exitCode);
        self::assertSame('https://ironcart.dev/scan/xyz', $outcome->viewUrl);
        self::assertStringContainsString('https://ironcart.dev/scan/xyz', $outcome->stdout);
    }

    public function testAuthFailureExitsNonZeroAndNeverLeaksBody(): void
    {
        $client = new FakeUploadClient();
        $client->queuedResponses = [
            new UploadClientResult(401, null, UploadClientResult::CATEGORY_AUTH),
        ];
        $runner = $this->makeRunner(enabled: true, token: 'wrong-token', client: $client);

        $outcome = $runner->run([]);

        self::assertNotSame(UploadRunnerOutcome::EXIT_OK, $outcome->exitCode);
        self::assertSame(UploadRunnerOutcome::EXIT_MISCONFIGURED, $outcome->exitCode);
        self::assertStringContainsString('invalid or expired token', $outcome->stderr);
        // Never leaks raw body; stderr is the stable categorical label.
        self::assertStringNotContainsString('{"', $outcome->stderr);
    }

    public function testServerErrorExitsNonZero(): void
    {
        $client = new FakeUploadClient();
        $client->queuedResponses = [
            new UploadClientResult(500, null, UploadClientResult::CATEGORY_SERVER),
        ];
        $runner = $this->makeRunner(enabled: true, token: 'tok', client: $client);

        $outcome = $runner->run([]);

        self::assertSame(UploadRunnerOutcome::EXIT_SERVER, $outcome->exitCode);
        self::assertStringContainsString('server error', $outcome->stderr);
    }

    public function testPayloadCarriesNormalisedBaseUrlAndModuleSource(): void
    {
        $client = new FakeUploadClient();
        $runner = $this->makeRunner(
            enabled: true,
            token: 'tok',
            client: $client,
            // Trailing slash + mixed-case host — must be normalised by the builder.
            baseUrl: 'https://SHOP.example.com/'
        );

        $outcome = $runner->run([]);

        self::assertSame(UploadRunnerOutcome::EXIT_OK, $outcome->exitCode);
        $payload = $client->invocations[0]['payload'];
        self::assertSame('https://shop.example.com', $payload['store']['base_url']);
        self::assertSame('1', $payload['schema_version']);
        self::assertStringStartsWith('ironcart-magento-scan/', $payload['source']);
    }

    public function testPayloadCarriesAuthorizationHeaderTokenVerbatim(): void
    {
        $client = new FakeUploadClient();
        $runner = $this->makeRunner(enabled: true, token: 'my-very-real-token', client: $client);

        $outcome = $runner->run([]);

        self::assertSame(UploadRunnerOutcome::EXIT_OK, $outcome->exitCode);
        self::assertSame('my-very-real-token', $client->invocations[0]['token']);
    }

    /**
     * Build a {@see UploadRunner} wired around fakes/mocks. Test
     * convenience — the production wiring lives in `etc/di.xml`.
     */
    private function makeRunner(
        bool $enabled,
        string $token,
        UploadClient $client,
        string $endpoint = 'https://ironcart.dev/api/scan/ingest',
        string $allowedHost = 'ironcart.dev',
        ?string $composerLock = null,
        string $baseUrl = 'https://shop.example.com/'
    ): UploadRunner {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnMap([
            [UploadConfig::PATH_ENABLED, 'default', null, $enabled],
        ]);
        $scope->method('getValue')->willReturnCallback(function (string $path) use ($token, $endpoint, $allowedHost, $baseUrl) {
            return match ($path) {
                UploadConfig::PATH_TOKEN => $token === '' ? null : 'enc:' . $token,
                UploadConfig::PATH_ENDPOINT => $endpoint,
                UploadConfig::PATH_ALLOWED_HOST => $allowedHost,
                'web/secure/base_url' => $baseUrl,
                'web/unsecure/base_url' => $baseUrl,
                default => null,
            };
        });

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(static function (string $enc): string {
            // Test convention: encrypted token is `enc:<plain>`.
            return str_starts_with($enc, 'enc:') ? substr($enc, 4) : $enc;
        });

        $config = new UploadConfig($scope, $encryptor);

        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        $productMetadata->method('getVersion')->willReturn('2.4.7-p3');
        $productMetadata->method('getEdition')->willReturn('Community');

        if ($composerLock === null) {
            $composerLock = $this->writeJson([
                'packages' => [
                    ['name' => 'magento/product-community-edition', 'version' => '2.4.7-p3'],
                ],
            ]);
        }
        $lockReader = new ComposerLockReader($composerLock);

        $payloadBuilder = new UploadPayloadBuilder(
            $productMetadata,
            $lockReader,
            $scope,
            'test-module-version'
        );

        return new UploadRunner(
            $config,
            $payloadBuilder,
            $client,
            'test-module-version'
        );
    }

    /**
     * Persist a temp JSON file and remember it for tearDown cleanup.
     *
     * @param array<string,mixed> $data
     */
    private function writeJson(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'iron-upload-');
        if ($path === false) {
            self::fail('Failed to allocate temp file');
        }
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
        $this->tempFiles[] = $path;
        return $path;
    }
}
