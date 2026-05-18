<?php

/**
 * IronCart_Scan — license-blob integration in the upload payload.
 *
 * Asserts the #103 acceptance criterion: the payload builder adds a
 * top-level `license_blob` field IFF a verifying license is configured.
 * Omitted by default (free-tier), omitted on bad-sig / expired blobs
 * (no point forwarding garbage), present-and-verbatim on the happy path.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Upload;

use IronCart\Scan\Check\License\LicenseConfig;
use IronCart\Scan\Check\License\LicenseVerifier;
use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use IronCart\Scan\Check\Upload\UploadPayloadBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\Upload\UploadPayloadBuilder
 */
class UploadPayloadBuilderLicenseTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('ext-sodium is required for license-blob payload tests');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
    }

    public function testNoLicenseConfigOmitsBlob(): void
    {
        // Default constructor argument: `LicenseConfig $licenseConfig = null`.
        // Operators on the free-tier never paste a blob, so the upload
        // payload simply omits the key.
        $builder = $this->makeBuilderNoLicense();

        $payload = $builder->build([]);

        self::assertArrayNotHasKey('license_blob', $payload);
    }

    public function testEmptyConfiguredBlobOmitsField(): void
    {
        $builder = $this->makeBuilder('', null);
        $payload = $builder->build([]);

        self::assertArrayNotHasKey(
            'license_blob',
            $payload,
            'Free-tier (no blob configured) MUST not emit license_blob'
        );
    }

    public function testVerifiedBlobIsIncludedVerbatim(): void
    {
        [$publicB64, $privateBytes] = $this->freshKeypair();

        $blob = $this->signBlob([
            'accountId' => 'acct_pro_payload',
            'sku' => 'magento-pro-monthly',
            'issuedAt' => (time() - 60) * 1000,
            'expiresAt' => (time() + 30 * 86400) * 1000,
            'nonce' => $this->b64url(random_bytes(16)),
            'sigVersion' => 1,
        ], $privateBytes);

        $builder = $this->makeBuilder($blob, new LicenseVerifier($publicB64));

        $payload = $builder->build([]);

        self::assertArrayHasKey('license_blob', $payload);
        self::assertSame($blob, $payload['license_blob']);
    }

    public function testTamperedBlobIsOmittedFromPayload(): void
    {
        [$publicB64, $privateBytes] = $this->freshKeypair();

        $valid = $this->signBlob([
            'accountId' => 'acct_pro_tampered',
            'sku' => 'magento-pro-monthly',
            'issuedAt' => (time() - 60) * 1000,
            'expiresAt' => (time() + 30 * 86400) * 1000,
            'nonce' => $this->b64url(random_bytes(16)),
            'sigVersion' => 1,
        ], $privateBytes);
        // Mutate the JSON segment so the signature is now wrong.
        [$json, $sig] = explode('.', $valid);
        $tampered = substr($json, 0, -1) . 'A' . '.' . $sig;

        $builder = $this->makeBuilder($tampered, new LicenseVerifier($publicB64));

        $payload = $builder->build([]);

        self::assertArrayNotHasKey(
            'license_blob',
            $payload,
            'A bad-signature blob must NEVER be forwarded to ironcart.dev'
        );
    }

    public function testExpiredBlobIsOmittedFromPayload(): void
    {
        [$publicB64, $privateBytes] = $this->freshKeypair();

        $expired = $this->signBlob([
            'accountId' => 'acct_pro_expired',
            'sku' => 'magento-pro-monthly',
            'issuedAt' => (time() - 86400 * 60) * 1000,
            'expiresAt' => (time() - 86400) * 1000, // 1 day ago
            'nonce' => $this->b64url(random_bytes(16)),
            'sigVersion' => 1,
        ], $privateBytes);

        $builder = $this->makeBuilder($expired, new LicenseVerifier($publicB64));

        $payload = $builder->build([]);

        self::assertArrayNotHasKey(
            'license_blob',
            $payload,
            'An expired blob must NEVER be forwarded to ironcart.dev'
        );
    }

    // ----- helpers --------------------------------------------------

    private function makeBuilderNoLicense(): UploadPayloadBuilder
    {
        return $this->buildBuilder(null);
    }

    private function makeBuilder(string $blobPlaintext, ?LicenseVerifier $verifier): UploadPayloadBuilder
    {
        $config = $this->makeLicenseConfig($blobPlaintext, $verifier ?? new LicenseVerifier(''));
        return $this->buildBuilder($config);
    }

    private function buildBuilder(?LicenseConfig $licenseConfig): UploadPayloadBuilder
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(static function (string $path): ?string {
            return match ($path) {
                'web/secure/base_url' => 'https://shop.example.com/',
                'web/unsecure/base_url' => 'https://shop.example.com/',
                default => null,
            };
        });

        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        $productMetadata->method('getVersion')->willReturn('2.4.7-p3');
        $productMetadata->method('getEdition')->willReturn('Community');

        $lockPath = $this->writeJson([
            'packages' => [
                ['name' => 'magento/product-community-edition', 'version' => '2.4.7-p3'],
            ],
        ]);
        $lockReader = new ComposerLockReader($lockPath);

        return new UploadPayloadBuilder(
            $productMetadata,
            $lockReader,
            $scope,
            'test-module-version',
            $licenseConfig
        );
    }

    private function makeLicenseConfig(string $blobPlaintext, LicenseVerifier $verifier): LicenseConfig
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(static function (string $path) use ($blobPlaintext): ?string {
            if ($path === LicenseConfig::PATH_BLOB) {
                return $blobPlaintext === '' ? null : 'enc:' . $blobPlaintext;
            }
            return null;
        });

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(static function (string $enc): string {
            return str_starts_with($enc, 'enc:') ? substr($enc, 4) : $enc;
        });

        return new LicenseConfig($scope, $encryptor, $verifier);
    }

    /**
     * @param array{
     *     accountId:string,sku:string,issuedAt:int,expiresAt:int,
     *     nonce:string,sigVersion:int
     * } $payload
     */
    private function signBlob(array $payload, string $privateBytes): string
    {
        $ordered = [
            'accountId' => $payload['accountId'],
            'sku' => $payload['sku'],
            'issuedAt' => $payload['issuedAt'],
            'expiresAt' => $payload['expiresAt'],
            'nonce' => $payload['nonce'],
            'sigVersion' => $payload['sigVersion'],
        ];
        $canonical = json_encode(
            $ordered,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $sig = sodium_crypto_sign_detached($canonical, $privateBytes);
        return $this->b64url($canonical) . '.' . $this->b64url($sig);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function freshKeypair(): array
    {
        $kp = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($kp);
        $privateKey = sodium_crypto_sign_secretkey($kp);
        return [base64_encode($publicKey), $privateKey];
    }

    private function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeJson(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'iron-upload-license-');
        if ($path === false) {
            self::fail('Failed to allocate temp file');
        }
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
        $this->tempFiles[] = $path;
        return $path;
    }
}
