<?php

/**
 * IronCart_Scan — license-config accessor.
 *
 * Thin wrapper over `ScopeConfigInterface` that exposes the
 * `ironcart_scan/license/blob` admin setting. Reading the blob through
 * this class — instead of inlining `getValue()` calls inside the upload
 * payload builder — lets the unit tests stub the configuration surface
 * without dragging in Magento's scope plumbing. Mirrors the
 * {@see \IronCart\Scan\Check\Upload\UploadConfig} pattern.
 *
 * Caching: the verifier result is cached in process memory for the
 * lifetime of THIS object. v3 wires the payload builder + license
 * config as `shared="true"` (Magento default), so a single
 * `bin/magento ironcart:scan --upload` invocation only verifies the blob
 * once — repeated `parsedClaims()` calls return the cached array.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 *
 * @see https://github.com/IronCartLabs/IronCartM2/issues/103
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\License;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Read-only view of the `ironcart_scan/license/*` admin config.
 */
class LicenseConfig
{
    public const PATH_BLOB = 'ironcart_scan/license/blob';

    /**
     * Memoised verifier result. `null` means "not computed yet"; a
     * verifier-shaped array means "already computed, return as-is".
     *
     * @var null|array{
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
     */
    private ?array $cachedResult = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly LicenseVerifier $verifier
    ) {
    }

    /**
     * Return the decrypted license blob, or an empty string when no
     * blob is configured. Empty string means "no Pro license set" — the
     * upload runner treats that as a valid free-tier state, NOT as a
     * misconfiguration.
     */
    public function blob(): string
    {
        $raw = $this->scopeConfig->getValue(self::PATH_BLOB);
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        // Magento stores `<backend_model>Magento\Config\Model\Config\Backend\Encrypted</backend_model>`
        // values as ciphertext; the accessor returns the ciphertext, not the
        // plaintext. We decrypt here so the blob never sits in memory in
        // plaintext outside this single call site.
        $plain = $this->encryptor->decrypt($raw);
        return is_string($plain) ? $plain : '';
    }

    /**
     * Verify the configured blob (or memoised verify-result). Idempotent
     * within an object lifetime.
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
     */
    public function verifyResult(): array
    {
        if ($this->cachedResult !== null) {
            return $this->cachedResult;
        }
        $blob = $this->blob();
        // The verifier already treats an empty blob as REASON_MALFORMED;
        // we short-circuit here so the cached result distinguishes
        // "no license configured" from "blob is gibberish".
        if ($blob === '') {
            $this->cachedResult = [
                'ok' => false,
                'reason' => LicenseVerifier::REASON_MALFORMED,
                'payload' => null,
                'detail' => 'No license blob is configured.',
            ];
            return $this->cachedResult;
        }
        $this->cachedResult = $this->verifier->verify($blob);
        return $this->cachedResult;
    }

    /**
     * Convenience accessor. Returns `null` when no license blob is
     * configured OR when the configured blob failed verification (for
     * any reason — expired, bad sig, malformed, ...). Callers that need
     * to branch on the specific failure reason should use
     * {@see verifyResult()} directly.
     *
     * @return null|array{
     *     accountId: string,
     *     sku: string,
     *     issuedAt: int,
     *     expiresAt: int,
     *     nonce: string,
     *     sigVersion: int
     * }
     */
    public function parsedClaims(): ?array
    {
        $result = $this->verifyResult();
        return $result['ok'] === true ? $result['payload'] : null;
    }

    /**
     * Return the raw blob, but ONLY if it verifies cleanly. The upload
     * payload builder uses this — never the plain {@see blob()} —
     * because a corrupted-or-expired blob should not be forwarded to
     * the hosted backend. Returns `null` when no license is configured
     * or verification failed.
     */
    public function verifiedBlob(): ?string
    {
        $result = $this->verifyResult();
        if ($result['ok'] !== true) {
            return null;
        }
        return $this->blob();
    }
}
