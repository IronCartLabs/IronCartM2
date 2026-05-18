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
 * v6 (#123) — multi-store agency-friendly resolution order. The blob is
 * resolved in this order, highest precedence first:
 *
 *   1. CLI override on the live {@see ScanSession} (set by
 *      `bin/magento ironcart:scan --license=<blob>`). One-shot only;
 *      NEVER persisted to `core_config_data`. Optional dep — when no
 *      session is wired (e.g. cron-driven scans, third-party callers)
 *      this layer is skipped.
 *   2. Env var `IRONCART_SCAN_LICENSE_BLOB`. Plaintext blob; intended
 *      for Magento Cloud / Docker / CI environments where env injection
 *      beats admin UI configuration. Read at the call site so a
 *      mid-process `putenv()` is honoured.
 *   3. `core_config_data` value at `ironcart_scan/license/blob`. The
 *      existing admin UI paste flow is unchanged — per-website /
 *      per-store scope wins over default scope via Magento's standard
 *      `ScopeConfigInterface::getValue()` resolution, exactly as before.
 *
 * The verifier (`LicenseVerifier`) treats whatever the resolved blob is
 * with identical Ed25519 validation — there is NO signing-path change.
 * Layers 1 and 2 just provide alternate delivery paths for the same
 * signed-by-IronCartWeb artifact.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 *
 * @see https://github.com/IronCartLabs/IronCartM2/issues/103
 * @see https://github.com/IronCartLabs/IronCartM2/issues/123
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\License;

use IronCart\Scan\Check\ScanSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Read-only view of the `ironcart_scan/license/*` admin config, with
 * env-var and CLI-override fallbacks layered above it.
 */
class LicenseConfig
{
    public const PATH_BLOB = 'ironcart_scan/license/blob';

    /**
     * Env var consulted between the CLI override and `core_config_data`.
     * Resolved fresh on every {@see blob()} call so a mid-process
     * `putenv()` (used in tests, and in some Magento Cloud bootstraps)
     * is honoured.
     */
    public const ENV_BLOB = 'IRONCART_SCAN_LICENSE_BLOB';

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

    /**
     * @param ScanSession|null $scanSession Optional — when provided,
     *                                      a non-null {@see ScanSession::licenseOverride()}
     *                                      wins over both the env var
     *                                      and `core_config_data`. Left
     *                                      nullable so existing test
     *                                      fixtures and third-party DI
     *                                      overrides keep working.
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly LicenseVerifier $verifier,
        private readonly ?ScanSession $scanSession = null
    ) {
    }

    /**
     * Return the resolved license blob, or an empty string when no
     * blob is configured at any layer. Empty string means "no Pro
     * license set" — the upload runner treats that as a valid free-tier
     * state, NOT as a misconfiguration.
     *
     * Resolution order — see the class docblock for the rationale:
     *
     *   1. {@see ScanSession::licenseOverride()} (CLI `--license=`)
     *   2. Env var `IRONCART_SCAN_LICENSE_BLOB`
     *   3. `core_config_data` (`ironcart_scan/license/blob`),
     *      decrypted via the Magento encryptor
     */
    public function blob(): string
    {
        // 1. CLI override. `ScanSession` is nullable in this constructor
        // so cron / test wiring without an explicit ScanSession still
        // works; the override layer is simply skipped in that case.
        $override = $this->scanSession?->licenseOverride();
        if (is_string($override) && $override !== '') {
            return $override;
        }

        // 2. Env var. `getenv()` returns `false` when unset and the
        // literal value otherwise. Magento Cloud / Docker / CI inject
        // values here when admin UI paste is impractical (one Composer
        // install per client = many stores).
        $envBlob = getenv(self::ENV_BLOB);
        if (is_string($envBlob) && $envBlob !== '') {
            return $envBlob;
        }

        // 3. core_config_data (per-website > default via standard
        // Magento scope resolution).
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
