<?php

/**
 * IronCart_Scan — unit tests for the Ed25519 license verifier.
 *
 * Mirrors the IronCartWeb signer's `__tests__/sign.test.ts` coverage
 * map so a single source-of-truth wire-format change can never land on
 * one side and not the other:
 *
 *   - happy-path round-trip (sign here, verify here)
 *   - signature tamper        -> bad_signature
 *   - payload tamper          -> bad_signature
 *   - expired license         -> expired
 *   - not-yet-valid           -> not_yet_valid
 *   - clock-skew tolerance    -> ok
 *   - missing field           -> missing_field
 *   - unknown sig version     -> unknown_sig_version
 *   - malformed input         -> malformed
 *   - no-key compiled in      -> no_key
 *   - wrong-key blob          -> bad_signature
 *   - non-base64url junk      -> malformed
 *
 * Every test mints a fresh ephemeral Ed25519 keypair via ext-sodium and
 * passes the public half to {@see LicenseVerifier::__construct} — the
 * compiled-in {@see \IronCart\Scan\Check\License\LicensePublicKey} is
 * NEVER consulted by the unit suite. This matches the IronCartWeb
 * pattern of resetting the cached keypair between tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\License;

use IronCart\Scan\Check\License\LicenseVerifier;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\License\LicenseVerifier
 */
class LicenseVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('ext-sodium is required for LicenseVerifier tests');
        }
    }

    public function testRoundTripValidLicenseVerifies(): void
    {
        [$publicB64, $privateBytes] = $this->freshKeypair();

        $now = 1_700_000_000;
        $blob = $this->signBlob([
            'accountId' => 'acct_round_trip',
            'sku' => 'magento-pro-monthly',
            'issuedAt' => $now * 1000,
            'expiresAt' => ($now + 30 * 86400) * 1000,
            'nonce' => $this->b64url(random_bytes(16)),
            'sigVersion' => 1,
        ], $privateBytes);

        $verifier = new LicenseVerifier($publicB64);
        $result = $verifier->verify($blob, $now);

        self::assertTrue($result['ok'], 'Round-trip blob must verify: ' . $result['detail']);
        self::assertSame(LicenseVerifier::REASON_OK, $result['reason']);
        self::assertSame('acct_round_trip', $result['payload']['accountId']);
        self::assertSame('magento-pro-monthly', $result['payload']['sku']);
        self::assertSame(1, $result['payload']['sigVersion']);
    }

    public function testTamperedSignatureFailsBadSignature(): void
    {
        [$publicB64, $privateBytes] = $this->freshKeypair();

        $now = 1_700_000_000;
        $blob = $this->signBlob([
            'accountId' => 'acct_tamper_sig',
            'sku' => 'magento-pro-monthly',
            'issuedAt' => $now * 1000,
            'expiresAt' => ($now + 30 * 86400) * 1000,
            'nonce' => $this->b64url(random_bytes(16)),
            'sigVersion' => 1,
        ], $privateBytes);

        // Flip the last byte of the signature segment. We do that by
        // decoding, mutating, and re-encoding so the segment stays
        // valid base64url.
        [$json, $sig] = explode('.', $blob);
        $sigBytes = $this->b64urlDecode($sig);
        $sigBytes[strlen($sigBytes) - 1] = chr(ord($sigBytes[strlen($sigBytes) - 1]) ^ 0x01);
        $tampered = $json . '.' . $this->b64url($sigBytes);

        $verifier = new LicenseVerifier($publicB64);
        $result = $verifier->verify($tampered, $now);

        self::assertFalse($result['ok']);
        self::assertSame(LicenseVerifier::REASON_BAD_SIGNATURE, $result['reason']);
    }

    public function testTamperedPayloadFailsBadSignature(): void
    {
        [$publicB64, $privateBytes] = $this->freshKeypair();

        $now = 1_700_000_000;
        $payload = [
            'accountId' => 'acct_original',
            'sku' => 'magento-pro-monthly',
            'issuedAt' => $now * 1000,
            'expiresAt' => ($now + 30 * 86400) * 1000,
            'nonce' => $this->b64url(random_bytes(16)),
            'sigVersion' => 1,
        ];
        $blob = $this->signBlob($payload, $privateBytes);

        // Replace the JSON segment with one whose accountId differs but
        // keep the original signature. The verifier MUST refuse.
        $payload['accountId'] = 'acct_attacker';
        $tamperedJson = $this->canonicalize($payload);
        [, $sig] = explode('.', $blob);
        $tampered = $this->b64url($tamperedJson) . '.' . $sig;

        $verifier = new LicenseVerifier($publicB64);
        $result = $verifier->verify($tampered, $now);

        self::assertFalse($result['ok']);
        self::assertSame(LicenseVerifier::REASON_BAD_SIGNATURE, $result['reason']);
    }

    public function testExpiredLicenseReturnsExpired(): void
    {
        [$publicB64, $privateBytes] = $this->freshKeypair();

        $issuedAt = 1_700_000_000;
        $expiresAt = $issuedAt + 10; // 10s validity
        $blob = $this->signBlob([
            'accountId' => 'acct_expired',
            'sku' => 'magento-pro-monthly',
            'issuedAt' => $issuedAt * 1000,
            'expiresAt' => $expiresAt * 1000,
            'nonce' => $this->b64url(random_bytes(16)),
            'sigVersion' => 1,
        ], $privateBytes);

        // "now" is far past expiry + skew window.
        $verifier = new LicenseVerifier($publicB64);
        $result = $verifier->verify($blob, $expiresAt + 86400);

        self::assertFalse($result['ok']);
        self::assertSame(LicenseVerifier::REASON_EXPIRED, $result['reason']);
    }

    public function testClockSkewToleranceHonorsLicenseWithinWindow(): void
    {
        [$publicB64, $privateBytes] = $this->freshKeypair();

        $issuedAt = 1_700_000_000;
        $expiresAt = $issuedAt + 1; // 1s validity
        $blob = $this->signBlob([
            'accountId' => 'acct_skew_ok',
            'sku' => 'magento-pro-monthly',
            'issuedAt' => $issuedAt * 1000,
            'expiresAt' => $expiresAt * 1000,
            'nonce' => $this->b64url(random_bytes(16)),
            'sigVersion' => 1,
        ], $privateBytes);

        // Just past expiry but still inside the default 60s skew window.
        $verifier = new LicenseVerifier($publicB64);
        $result = $verifier->verify($blob, $expiresAt + 30);

        self::assertTrue($result['ok'], 'Skew window must honor a moments-past-expiry blob: ' . $result['detail']);
        self::assertSame(LicenseVerifier::REASON_OK, $result['reason']);
    }

    public function testFutureIssuedAtReturnsNotYetValid(): void
    {
        [$publicB64, $privateBytes] = $this->freshKeypair();

        $issuedAt = 1_700_000_000;
        $blob = $this->signBlob([
            'accountId' => 'acct_future',
            'sku' => 'magento-pro-monthly',
            'issuedAt' => $issuedAt * 1000,
            'expiresAt' => ($issuedAt + 86400) * 1000,
            'nonce' => $this->b64url(random_bytes(16)),
            'sigVersion' => 1,
        ], $privateBytes);

        // "now" is well before the issuer's clock (an hour earlier).
        $verifier = new LicenseVerifier($publicB64);
        $result = $verifier->verify($blob, $issuedAt - 3600);

        self::assertFalse($result['ok']);
        self::assertSame(LicenseVerifier::REASON_NOT_YET_VALID, $result['reason']);
    }

    public function testWrongKeyReturnsBadSignature(): void
    {
        [, $privateBytes] = $this->freshKeypair();
        [$otherPublicB64] = $this->freshKeypair();

        $now = 1_700_000_000;
        $blob = $this->signBlob([
            'accountId' => 'acct_wrong_key',
            'sku' => 'magento-pro-monthly',
            'issuedAt' => $now * 1000,
            'expiresAt' => ($now + 86400) * 1000,
            'nonce' => $this->b64url(random_bytes(16)),
            'sigVersion' => 1,
        ], $privateBytes);

        // Configure the verifier with a DIFFERENT public key.
        $verifier = new LicenseVerifier($otherPublicB64);
        $result = $verifier->verify($blob, $now);

        self::assertFalse($result['ok']);
        self::assertSame(LicenseVerifier::REASON_BAD_SIGNATURE, $result['reason']);
    }

    public function testMissingFieldReturnsMissingField(): void
    {
        [$publicB64, $privateBytes] = $this->freshKeypair();

        // Sign a payload that has no `expiresAt` field. The verifier
        // MUST surface REASON_MISSING_FIELD rather than chasing the
        // signature (which by construction WILL match this payload's
        // bytes — we signed it ourselves).
        $payload = [
            'accountId' => 'acct_missing',
            'sku' => 'magento-pro-monthly',
            'issuedAt' => 1_700_000_000_000,
            // expiresAt deliberately omitted
            'nonce' => $this->b64url(random_bytes(16)),
            'sigVersion' => 1,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $sig = sodium_crypto_sign_detached($json, $privateBytes);
        $blob = $this->b64url($json) . '.' . $this->b64url($sig);

        $verifier = new LicenseVerifier($publicB64);
        $result = $verifier->verify($blob, 1_700_000_000);

        self::assertFalse($result['ok']);
        self::assertSame(LicenseVerifier::REASON_MISSING_FIELD, $result['reason']);
    }

    public function testUnknownSigVersionReturnsUnknownSigVersion(): void
    {
        [$publicB64, $privateBytes] = $this->freshKeypair();

        $now = 1_700_000_000;
        $blob = $this->signBlob([
            'accountId' => 'acct_future_sig',
            'sku' => 'magento-pro-monthly',
            'issuedAt' => $now * 1000,
            'expiresAt' => ($now + 86400) * 1000,
            'nonce' => $this->b64url(random_bytes(16)),
            'sigVersion' => 99, // not in SUPPORTED_SIG_VERSIONS
        ], $privateBytes);

        $verifier = new LicenseVerifier($publicB64);
        $result = $verifier->verify($blob, $now);

        self::assertFalse($result['ok']);
        self::assertSame(LicenseVerifier::REASON_UNKNOWN_SIG_VERSION, $result['reason']);
    }

    /**
     * @dataProvider provideMalformedBlobs
     */
    public function testMalformedBlobReturnsMalformed(?string $blob, string $why): void
    {
        [$publicB64] = $this->freshKeypair();
        $verifier = new LicenseVerifier($publicB64);

        $result = $verifier->verify($blob, 1_700_000_000);

        self::assertFalse($result['ok'], $why);
        self::assertContains(
            $result['reason'],
            [LicenseVerifier::REASON_MALFORMED, LicenseVerifier::REASON_BAD_SIGNATURE],
            'Malformed inputs may fall into MALFORMED or BAD_SIGNATURE depending on the segment that breaks: ' . $why
        );
    }

    /**
     * @return iterable<string, array{0:?string,1:string}>
     */
    public static function provideMalformedBlobs(): iterable
    {
        yield 'null blob' => [null, 'null is not a parseable blob'];
        yield 'empty string' => ['', 'empty string is not a parseable blob'];
        yield 'no dot separator' => ['abcdef', 'missing the json.sig separator'];
        yield 'leading dot' => ['.deadbeef', 'empty json segment'];
        yield 'trailing dot' => ['eyJhYmMiOjF9.', 'empty signature segment'];
        yield 'three segments' => ['eyJhYmMiOjF9.AAAA.BBBB', 'too many segments'];
        yield 'non-base64 json' => ['@@@@@@@.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'non-base64url in json segment'];
        yield 'json not an object' => [self::b64urlStatic('[1,2,3]') . '.' . str_repeat('A', 86), 'json segment is a JSON array, not an object'];
        yield 'garbage json' => [self::b64urlStatic('not json at all') . '.' . str_repeat('A', 86), 'json segment is not valid JSON'];
    }

    public function testNoKeyCompiledInReturnsNoKey(): void
    {
        // Empty public key string — simulates an unstamped release
        // build (the constant `LicensePublicKey::PUBLIC_KEY_BASE64` is
        // empty in repo source until the release pipeline overwrites it).
        $verifier = new LicenseVerifier('');

        $result = $verifier->verify('whatever.blob', 1_700_000_000);

        self::assertFalse($result['ok']);
        self::assertSame(LicenseVerifier::REASON_NO_KEY, $result['reason']);
    }

    public function testMalformedPublicKeyReturnsNoKey(): void
    {
        // Garbage public key — simulates a release pipeline that
        // stamped the wrong value (e.g. truncated). Treat as NO_KEY
        // rather than throwing — operators should see the same actionable
        // failure as an unstamped build.
        $verifier = new LicenseVerifier('not-valid-base64-key');

        $result = $verifier->verify('whatever.blob', 1_700_000_000);

        self::assertFalse($result['ok']);
        self::assertSame(LicenseVerifier::REASON_NO_KEY, $result['reason']);
    }

    public function testNonBase64UrlJsonSegmentReturnsMalformed(): void
    {
        [$publicB64] = $this->freshKeypair();
        // A valid-length sig segment but junk in the JSON segment.
        $verifier = new LicenseVerifier($publicB64);
        $blob = '@@@@@.' . str_repeat('A', 86);
        $result = $verifier->verify($blob, 1_700_000_000);

        self::assertFalse($result['ok']);
        self::assertSame(LicenseVerifier::REASON_MALFORMED, $result['reason']);
    }

    /**
     * Sign a payload and return the on-the-wire blob using the same
     * canonicalization rules the production verifier expects.
     *
     * @param array{
     *     accountId:string,sku:string,issuedAt:int,expiresAt:int,
     *     nonce:string,sigVersion:int
     * } $payload
     */
    private function signBlob(array $payload, string $privateBytes): string
    {
        $canonical = $this->canonicalize($payload);
        $sig = sodium_crypto_sign_detached($canonical, $privateBytes);
        return $this->b64url($canonical) . '.' . $this->b64url($sig);
    }

    /**
     * Canonical JSON matching the verifier / signer contract (fixed
     * field order, no whitespace, JSON_UNESCAPED_SLASHES |
     * JSON_UNESCAPED_UNICODE).
     *
     * @param array<string,mixed> $payload
     */
    private function canonicalize(array $payload): string
    {
        $ordered = [
            'accountId' => $payload['accountId'],
            'sku' => $payload['sku'],
            'issuedAt' => $payload['issuedAt'],
            'expiresAt' => $payload['expiresAt'],
            'nonce' => $payload['nonce'],
            'sigVersion' => $payload['sigVersion'],
        ];
        return json_encode(
            $ordered,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Generate a fresh Ed25519 keypair and return `[publicKeyBase64,
     * privateKeyBytes]`. The verifier consumes the public half as
     * base64; libsodium signs with raw bytes — match each call site.
     *
     * @return array{0:string,1:string}
     */
    private function freshKeypair(): array
    {
        $kp = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($kp);
        $privateKey = sodium_crypto_sign_secretkey($kp);
        return [base64_encode($publicKey), $privateKey];
    }

    /**
     * Base64url encode (no padding, `-_` alphabet) — matches the
     * IronCartWeb signer's `base64urlEncode`.
     */
    private function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Static variant of {@see b64url()} so the data provider (a static
     * method per PHPUnit 10) can use it without instantiating the test
     * class.
     */
    private static function b64urlStatic(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $segment): string
    {
        $standard = strtr($segment, '-_', '+/');
        $padded = $standard . str_repeat('=', (4 - (strlen($standard) % 4)) % 4);
        $decoded = base64_decode($padded, true);
        self::assertNotFalse($decoded, 'base64url decode failed in test helper');
        return $decoded;
    }
}
