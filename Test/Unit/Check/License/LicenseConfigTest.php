<?php

/**
 * IronCart_Scan — unit tests for {@see LicenseConfig}.
 *
 * Covers the four states the upload payload builder branches on:
 *
 *   1. No blob configured              -> verifyResult().ok = false,
 *                                          reason = malformed,
 *                                          verifiedBlob() = null
 *   2. Blob configured, verifies       -> ok = true, parsedClaims()
 *                                          returns the typed payload,
 *                                          verifiedBlob() returns the
 *                                          plaintext blob
 *   3. Blob configured, fails verify   -> ok = false, the underlying
 *                                          reason surfaces, verifiedBlob()
 *                                          returns null
 *   4. Multiple calls in one lifetime  -> verifier invoked once
 *                                          (per-scan caching invariant)
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\License;

use IronCart\Scan\Check\License\LicenseConfig;
use IronCart\Scan\Check\License\LicenseVerifier;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\License\LicenseConfig
 */
class LicenseConfigTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('ext-sodium is required for LicenseConfig tests');
        }
    }

    public function testNoBlobConfiguredReturnsNullClaims(): void
    {
        $config = $this->makeConfig('');

        self::assertSame('', $config->blob());
        self::assertNull($config->parsedClaims());
        self::assertNull($config->verifiedBlob());

        $result = $config->verifyResult();
        self::assertFalse($result['ok']);
        self::assertSame(LicenseVerifier::REASON_MALFORMED, $result['reason']);
    }

    public function testValidBlobReturnsTypedClaims(): void
    {
        [$publicB64, $privateBytes] = $this->freshKeypair();

        $now = 1_700_000_000;
        $blob = $this->signBlob([
            'accountId' => 'acct_pro_1',
            'sku' => 'magento-pro-annual',
            'issuedAt' => $now * 1000,
            'expiresAt' => ($now + 365 * 86400) * 1000,
            'nonce' => $this->b64url(random_bytes(16)),
            'sigVersion' => 1,
        ], $privateBytes);

        $verifier = new LicenseVerifier($publicB64);
        // Pin "now" by passing a wrapper that calls verify() with our
        // synthetic time — but the real config flow uses time() implicitly.
        // For this test we issue a blob whose validity covers real time
        // so the production code path works without injection.
        $longLivedBlob = $this->signBlob([
            'accountId' => 'acct_pro_long',
            'sku' => 'magento-pro-annual',
            'issuedAt' => (time() - 60) * 1000,
            'expiresAt' => (time() + 365 * 86400) * 1000,
            'nonce' => $this->b64url(random_bytes(16)),
            'sigVersion' => 1,
        ], $privateBytes);

        $config = $this->makeConfigWithVerifier($longLivedBlob, $verifier);

        $claims = $config->parsedClaims();
        self::assertIsArray($claims);
        self::assertSame('acct_pro_long', $claims['accountId']);
        self::assertSame('magento-pro-annual', $claims['sku']);
        self::assertSame(1, $claims['sigVersion']);

        self::assertSame($longLivedBlob, $config->verifiedBlob());
    }

    public function testTamperedBlobReturnsNullVerifiedBlob(): void
    {
        [$publicB64, $privateBytes] = $this->freshKeypair();

        $blob = $this->signBlob([
            'accountId' => 'acct_tampered',
            'sku' => 'magento-pro-monthly',
            'issuedAt' => (time() - 60) * 1000,
            'expiresAt' => (time() + 86400) * 1000,
            'nonce' => $this->b64url(random_bytes(16)),
            'sigVersion' => 1,
        ], $privateBytes);

        // Mutate the JSON segment so the signature no longer matches.
        [$json, $sig] = explode('.', $blob);
        $tampered = substr($json, 0, -1) . 'A' . '.' . $sig;

        $verifier = new LicenseVerifier($publicB64);
        $config = $this->makeConfigWithVerifier($tampered, $verifier);

        self::assertNull($config->parsedClaims());
        self::assertNull($config->verifiedBlob());
        self::assertSame(LicenseVerifier::REASON_BAD_SIGNATURE, $config->verifyResult()['reason']);
    }

    public function testVerifierCalledOnlyOncePerLifetime(): void
    {
        // Use a verifier double that increments a counter every call.
        // This asserts the per-scan caching invariant: the upload payload
        // builder may call verifiedBlob() / parsedClaims() / verifyResult()
        // in any combination, but the underlying sodium_* verify happens
        // ONCE per LicenseConfig instance.
        [$publicB64, $privateBytes] = $this->freshKeypair();

        $blob = $this->signBlob([
            'accountId' => 'acct_caching',
            'sku' => 'magento-pro-monthly',
            'issuedAt' => (time() - 60) * 1000,
            'expiresAt' => (time() + 86400) * 1000,
            'nonce' => $this->b64url(random_bytes(16)),
            'sigVersion' => 1,
        ], $privateBytes);

        $counter = new class ($publicB64) extends LicenseVerifier {
            public int $verifyCalls = 0;
            public function verify(?string $blob, ?int $nowSeconds = null): array
            {
                $this->verifyCalls++;
                return parent::verify($blob, $nowSeconds);
            }
        };

        $config = $this->makeConfigWithVerifier($blob, $counter);

        // Three calls; verifier MUST be invoked exactly once.
        $config->verifyResult();
        $config->parsedClaims();
        $config->verifiedBlob();

        self::assertSame(1, $counter->verifyCalls, 'LicenseConfig must memoise the verify result');
    }

    // ----- helpers --------------------------------------------------

    /**
     * Build a {@see LicenseConfig} whose underlying scope/encryptor stub
     * returns the supplied blob plaintext.
     */
    private function makeConfig(string $blobPlaintext): LicenseConfig
    {
        return $this->makeConfigWithVerifier($blobPlaintext, new LicenseVerifier(''));
    }

    private function makeConfigWithVerifier(string $blobPlaintext, LicenseVerifier $verifier): LicenseConfig
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
}
