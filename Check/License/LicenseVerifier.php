<?php

/**
 * IronCart_Scan — Ed25519 license-blob verifier.
 *
 * Mirrors the IronCartWeb signer in `lib/license/sign.ts`. The two MUST
 * agree on the byte-level wire format or production licenses will not
 * verify on merchant stores:
 *
 *   blob := base64url(canonical_json) '.' base64url(signature)
 *
 *   canonical_json := utf8 JSON object with fields in fixed order:
 *       accountId, sku, issuedAt, expiresAt, nonce, sigVersion
 *     where issuedAt / expiresAt are unix-epoch MILLISECONDS, nonce is
 *     base64 of 16 random bytes, sigVersion is an integer >= 1.
 *
 *   signature := 64-byte Ed25519 detached signature over the raw
 *     canonical_json UTF-8 byte stream (NOT over its base64url-encoded
 *     form). 64 bytes is the only valid length for an Ed25519 detached
 *     signature — anything else is structurally invalid and rejected
 *     before the public key is even consulted.
 *
 * Failure modes are surfaced via stable string reasons (constants
 * below) so callers can branch on policy without parsing English error
 * messages — same pattern as {@see UploadClientResult::CATEGORY_*}.
 *
 * Security posture:
 *
 *   - Verifies entirely offline against a compiled-in public key (see
 *     {@see LicensePublicKey}). No outbound network. No env-var read.
 *   - The verifier never throws on a bad blob — every blob produces an
 *     {@see Result} value. The only exceptional case ({@see RuntimeException})
 *     is `ext-sodium` being absent from the host PHP build, which is a
 *     deploy bug not a license-content bug.
 *   - Payload integrity: we re-canonicalize the JSON from typed fields
 *     before verifying so the signature is checked against the SAME
 *     bytes the signer signed. A tampered JSON segment with extra
 *     unknown fields would deserialize fine but re-encoding strips them
 *     out, the signature then fails to verify, and we return
 *     {@see REASON_BAD_SIGNATURE}. This is the canonical mitigation for
 *     "the signer ignored an extra field but the verifier honored it"
 *     attacks.
 *   - Clock skew: we accept a tolerated wall-clock skew (default 60s)
 *     between the issuer (Vercel) and the verifier (merchant store)
 *     because the two are independent clocks. A wildly future-dated
 *     blob is suspicious and rejected with {@see REASON_NOT_YET_VALID};
 *     a moments-past-expiry blob (within skew) is honored to avoid
 *     flapping renewals.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 *
 * @see https://github.com/IronCartLabs/IronCartM2/issues/103
 * @see \IronCart\Scan\Check\License\LicensePublicKey
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\License;

use RuntimeException;

/**
 * Stateless verifier for the on-the-wire license blob.
 */
class LicenseVerifier
{
    public const REASON_OK = 'ok';
    public const REASON_NO_KEY = 'no_key';
    public const REASON_MALFORMED = 'malformed';
    public const REASON_BAD_SIGNATURE = 'bad_signature';
    public const REASON_MISSING_FIELD = 'missing_field';
    public const REASON_UNKNOWN_SIG_VERSION = 'unknown_sig_version';
    public const REASON_NOT_YET_VALID = 'not_yet_valid';
    public const REASON_EXPIRED = 'expired';

    /**
     * Default tolerated wall-clock skew between issuer (Vercel) and
     * verifier (merchant store) in seconds. Mirrors
     * `DEFAULT_CLOCK_SKEW_MS / 1000` on the IronCartWeb signer side.
     */
    public const DEFAULT_CLOCK_SKEW_SECONDS = 60;

    /**
     * Sig versions this verifier honors. Bumped in lock-step with the
     * release-pipeline public-key stamp during rotation. Single-element
     * set ships in v0 — the verifier carries ONE public key, so it
     * cannot dual-verify across a rotation overlap (that's the signer's
     * job).
     *
     * @var list<int>
     */
    public const SUPPORTED_SIG_VERSIONS = [1];

    /**
     * Field order in the canonical JSON encoding. MUST match
     * `canonicalizePayload` in `lib/license/sign.ts` byte-for-byte.
     *
     * @var list<string>
     */
    private const CANONICAL_FIELD_ORDER = [
        'accountId',
        'sku',
        'issuedAt',
        'expiresAt',
        'nonce',
        'sigVersion',
    ];

    private const ED25519_SIGNATURE_BYTES = 64;
    private const ED25519_PUBLIC_KEY_BYTES = 32;

    /**
     * @param string $publicKeyBase64 Base64 of the 32-byte Ed25519 public
     *                                key. Defaults to the compiled-in
     *                                {@see LicensePublicKey::PUBLIC_KEY_BASE64}.
     *                                Tests pass their own test keypair public
     *                                bytes here.
     * @param int    $clockSkewSeconds Tolerated skew. Defaults to
     *                                {@see DEFAULT_CLOCK_SKEW_SECONDS}.
     */
    public function __construct(
        private readonly string $publicKeyBase64 = LicensePublicKey::PUBLIC_KEY_BASE64,
        private readonly int $clockSkewSeconds = self::DEFAULT_CLOCK_SKEW_SECONDS
    ) {
    }

    /**
     * Verify a license blob and return the parsed claims plus a status
     * reason. The shape mirrors `VerifyLicenseResult` in
     * `lib/license/sign.ts`:
     *
     *   [
     *     'ok'      => bool,
     *     'reason'  => self::REASON_*,
     *     'payload' => null | array{accountId,sku,issuedAt,expiresAt,nonce,sigVersion},
     *     'detail'  => string  // human-readable, NEVER logged verbatim
     *                          // to merchant-facing output past a single
     *                          // operator-debug line; treat as PII-adjacent.
     *   ]
     *
     * @param string|null $blob The on-the-wire blob. Empty/null returns
     *                          {@see REASON_MALFORMED} (caller decides
     *                          whether that's an error or "no license
     *                          configured").
     * @param int|null    $nowSeconds Pin clock for tests. Defaults to time().
     *
     * @return array{
     *     ok: bool,
     *     reason: string,
     *     payload: null|array{
     *         accountId: string,
     *         sku: string,
     *         issuedAt: int,
     *         expiresAt: int,
     *         nonce: string,
     *         sigVersion: int
     *     },
     *     detail: string
     * }
     *
     * @throws RuntimeException When ext-sodium is unavailable. A deploy
     *                          bug — every supported PHP cell in CI
     *                          (8.1 / 8.2 / 8.3) has `ext-sodium`
     *                          loaded, and `composer.json` declares it
     *                          as a hard require.
     */
    public function verify(?string $blob, ?int $nowSeconds = null): array
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            // Hard fail — this is a deploy misconfiguration, not a
            // license-content issue. The runner / config caller should
            // surface this as a 500-equivalent.
            throw new RuntimeException(
                'ext-sodium is required for license verification but is not loaded. '
                . 'Install php-sodium (`apt install php-sodium` / `pecl install libsodium`) '
                . 'and re-run `bin/magento setup:di:compile`.'
            );
        }

        if ($this->publicKeyBase64 === '') {
            // Unstamped build (source ships with empty key). Refuse
            // every blob so a dev build cannot accidentally honor a
            // production license.
            return $this->fail(self::REASON_NO_KEY, 'No license public key compiled into this build.');
        }

        if (!is_string($blob) || $blob === '') {
            return $this->fail(self::REASON_MALFORMED, 'License blob is empty.');
        }

        // Decode the public key once per call. Cheap (32 bytes) and
        // keeps the verifier stateless — no boot-time work in DI wiring.
        $publicKey = self::base64StrictDecode($this->publicKeyBase64);
        if ($publicKey === null || strlen($publicKey) !== self::ED25519_PUBLIC_KEY_BYTES) {
            // Stamped with garbage. Treat as REASON_NO_KEY rather than
            // throwing — operators with a broken release build should
            // get the same actionable failure they'd see with no key
            // stamped at all.
            return $this->fail(
                self::REASON_NO_KEY,
                'Compiled-in license public key is malformed (decoded to '
                . ($publicKey === null ? 'null' : strlen($publicKey) . ' bytes')
                . '; expected ' . self::ED25519_PUBLIC_KEY_BYTES . ').'
            );
        }

        // Split JSON segment from signature segment. Reject multi-dot
        // blobs outright — `a.b.c` is not our wire format.
        $dot = strpos($blob, '.');
        if ($dot === false || $dot === 0 || $dot === strlen($blob) - 1) {
            return $this->fail(self::REASON_MALFORMED, "License blob must be '<base64url-json>.<base64url-sig>'.");
        }
        if (strpos($blob, '.', $dot + 1) !== false) {
            return $this->fail(self::REASON_MALFORMED, "License blob contains more than one '.' separator.");
        }

        $jsonPart = substr($blob, 0, $dot);
        $sigPart = substr($blob, $dot + 1);

        $jsonBytes = self::base64UrlDecode($jsonPart);
        if ($jsonBytes === null || $jsonBytes === '') {
            return $this->fail(self::REASON_MALFORMED, 'License blob JSON segment is not valid base64url.');
        }
        $sigBytes = self::base64UrlDecode($sigPart);
        if ($sigBytes === null) {
            return $this->fail(self::REASON_MALFORMED, 'License blob signature segment is not valid base64url.');
        }
        // Ed25519 signatures are exactly 64 bytes. Surface a length
        // mismatch as REASON_BAD_SIGNATURE (rather than MALFORMED) to
        // match the IronCartWeb signer's behavior — see `lib/license/sign.ts`.
        if (strlen($sigBytes) !== self::ED25519_SIGNATURE_BYTES) {
            return $this->fail(self::REASON_BAD_SIGNATURE, 'License signature is not 64 bytes.');
        }

        // Parse + field-validate the JSON BEFORE the signature check so
        // a known-bad payload (missing field, wrong sigVersion) surfaces
        // the actionable reason instead of a generic `bad_signature`.
        $parsed = json_decode($jsonBytes, true);
        if (!is_array($parsed)) {
            return $this->fail(self::REASON_MALFORMED, 'License payload is not a JSON object.');
        }

        $typed = $this->validateAndType($parsed);
        if (is_string($typed)) {
            // validateAndType returns either the typed array or a
            // reason-string when it can't.
            return $this->fail(
                $typed,
                $typed === self::REASON_MISSING_FIELD
                    ? 'License payload is missing a required field.'
                    : 'License payload field is malformed.'
            );
        }

        if (!in_array($typed['sigVersion'], self::SUPPORTED_SIG_VERSIONS, true)) {
            return $this->fail(
                self::REASON_UNKNOWN_SIG_VERSION,
                'License sigVersion ' . $typed['sigVersion'] . ' is not supported by this module build. '
                . 'Update the IronCart_Scan module.'
            );
        }

        // Re-canonicalize from the typed fields so the signature is
        // verified against the SAME byte stream the signer signed.
        // Catches payloads with extra unknown fields that an attacker
        // hopes the verifier honors.
        $canonical = $this->canonicalize($typed);

        $signatureOk = false;
        try {
            $signatureOk = sodium_crypto_sign_verify_detached($sigBytes, $canonical, $publicKey);
        } catch (\SodiumException) {
            // libsodium throws on byte-length mismatch even though we
            // already checked. Defense in depth — treat as bad sig.
            $signatureOk = false;
        }
        if (!$signatureOk) {
            return $this->fail(self::REASON_BAD_SIGNATURE, 'License signature verification failed.');
        }

        // Wall-clock checks. Use seconds throughout PHP — the signer
        // emits millis, so divide by 1000. The skew is added to both
        // edges so a license issued moments in the future on a slightly
        // ahead-of-skew issuer clock is still accepted.
        $now = $nowSeconds ?? time();
        $issuedAtSec = (int) floor($typed['issuedAt'] / 1000);
        $expiresAtSec = (int) floor($typed['expiresAt'] / 1000);
        if ($issuedAtSec > $now + $this->clockSkewSeconds) {
            return $this->fail(
                self::REASON_NOT_YET_VALID,
                'License issuedAt is in the future. Check the merchant server clock.'
            );
        }
        if ($expiresAtSec + $this->clockSkewSeconds < $now) {
            return $this->fail(
                self::REASON_EXPIRED,
                'License expired at unix-second ' . $expiresAtSec . '; now is ' . $now . '.'
            );
        }

        return [
            'ok' => true,
            'reason' => self::REASON_OK,
            'payload' => $typed,
            'detail' => '',
        ];
    }

    /**
     * Build a {@see verify()}-shaped failure result. Centralised so the
     * shape never drifts as new failure reasons are added.
     *
     * @return array{ok: false, reason: string, payload: null, detail: string}
     */
    private function fail(string $reason, string $detail): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
            'payload' => null,
            'detail' => $detail,
        ];
    }

    /**
     * Validate every required field is present with the right type and
     * return a typed array on success, or one of the REASON_* constants
     * on failure.
     *
     * @param array<mixed> $parsed
     *
     * @return array{
     *     accountId: string,
     *     sku: string,
     *     issuedAt: int,
     *     expiresAt: int,
     *     nonce: string,
     *     sigVersion: int
     * }|string
     */
    private function validateAndType(array $parsed): array|string
    {
        // Required strings, MUST be non-empty.
        foreach (['accountId', 'sku', 'nonce'] as $field) {
            if (!array_key_exists($field, $parsed)) {
                return self::REASON_MISSING_FIELD;
            }
            if (!is_string($parsed[$field]) || $parsed[$field] === '') {
                return self::REASON_MALFORMED;
            }
        }
        // Required numerics. The signer emits whole-millis ints; PHP
        // json_decode returns int when the JSON token has no fractional
        // part. Reject floats so a forged blob with `1700000000.5` can't
        // sneak past the type-check.
        foreach (['issuedAt', 'expiresAt', 'sigVersion'] as $field) {
            if (!array_key_exists($field, $parsed)) {
                return self::REASON_MISSING_FIELD;
            }
            if (!is_int($parsed[$field])) {
                return self::REASON_MALFORMED;
            }
        }

        return [
            'accountId' => $parsed['accountId'],
            'sku' => $parsed['sku'],
            'issuedAt' => $parsed['issuedAt'],
            'expiresAt' => $parsed['expiresAt'],
            'nonce' => $parsed['nonce'],
            'sigVersion' => $parsed['sigVersion'],
        ];
    }

    /**
     * Re-encode the typed payload in the same byte-deterministic form
     * the signer used. MUST stay byte-identical to
     * `canonicalizePayload` in `lib/license/sign.ts`:
     *
     *   - Field order fixed (CANONICAL_FIELD_ORDER).
     *   - No whitespace.
     *   - Forward-slash NOT escaped (`JSON_UNESCAPED_SLASHES`).
     *   - Unicode NOT escaped (`JSON_UNESCAPED_UNICODE`) — Node's default.
     *
     * @param array{
     *     accountId: string,
     *     sku: string,
     *     issuedAt: int,
     *     expiresAt: int,
     *     nonce: string,
     *     sigVersion: int
     * } $typed
     */
    private function canonicalize(array $typed): string
    {
        // Build the assoc array in fixed key order so json_encode emits
        // the keys in CANONICAL_FIELD_ORDER. PHP preserves insertion
        // order for associative arrays — this is contractual since 7.0.
        $ordered = [];
        foreach (self::CANONICAL_FIELD_ORDER as $key) {
            $ordered[$key] = $typed[$key];
        }

        $encoded = json_encode(
            $ordered,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        return $encoded;
    }

    /**
     * Decode a base64url-encoded segment (no padding, `-_` alphabet) to
     * raw bytes. Returns null on completely invalid input. We
     * intentionally use the strict variant — `base64_decode($s, true)` —
     * so junk characters don't silently produce truncated output.
     */
    private static function base64UrlDecode(string $segment): ?string
    {
        // Restore standard alphabet and add padding before passing to
        // base64_decode strict. PHP doesn't ship a native base64url
        // helper.
        $standard = strtr($segment, '-_', '+/');
        $padded = $standard . str_repeat('=', (4 - (strlen($standard) % 4)) % 4);
        $decoded = base64_decode($padded, true);
        return $decoded === false ? null : $decoded;
    }

    /**
     * Strict base64 (standard alphabet, padded) decode helper used for
     * the compiled-in public key only. Returns null on invalid input.
     */
    private static function base64StrictDecode(string $value): ?string
    {
        $decoded = base64_decode($value, true);
        return $decoded === false ? null : $decoded;
    }
}
