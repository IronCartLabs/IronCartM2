<?php

/**
 * IronCart_Scan — upload config reader.
 *
 * Thin wrapper over `ScopeConfigInterface` that exposes the three
 * `ironcart_scan/upload/*` settings the `--upload` flow needs. Reading
 * config through this class — instead of inlining `getValue()` calls
 * inside {@see UploadRunner} — lets the unit tests stub the configuration
 * surface without dragging in Magento's scope plumbing.
 *
 * The endpoint defaults to the production ingest URL. A dev-mode operator
 * may override it for staging / local Next.js (`http://localhost:3000/...`)
 * but the override path is documented in `docs/UPLOAD.md` only — the
 * `<field>` in `system.xml` is hidden behind `if_module_enabled` so it
 * doesn't surface in the merchant admin UI under normal use.
 *
 * v6 (#123) — multi-store agency-friendly resolution. {@see token()} and
 * {@see isEnabled()} now consult, in order:
 *
 *   1. CLI override on the live {@see ScanSession} (`--upload-token=`
 *      from `bin/magento ironcart:scan`). One-shot only; NEVER persisted
 *      to `core_config_data`.
 *   2. Env vars `IRONCART_SCAN_UPLOAD_TOKEN` / `IRONCART_SCAN_UPLOAD_ENABLED`.
 *   3. `core_config_data` (existing admin UI paste flow, unchanged).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 *
 * @see https://github.com/IronCartLabs/IronCartM2/issues/123
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Upload;

use IronCart\Scan\Check\ScanSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Read-only view of the `ironcart_scan/upload/*` admin config, with
 * env-var and CLI-override fallbacks layered above it.
 */
class UploadConfig
{
    public const PATH_ENABLED = 'ironcart_scan/upload/enabled';
    public const PATH_TOKEN = 'ironcart_scan/upload/token';
    public const PATH_ENDPOINT = 'ironcart_scan/upload/endpoint';
    public const PATH_ALLOWED_HOST = 'ironcart_scan/upload/allowed_host';

    /**
     * Env vars consulted between the CLI override and `core_config_data`
     * for the two upload-gating settings. The endpoint + allowed-host
     * intentionally stay admin-config-only — they're QA/staging knobs,
     * not multi-store agency knobs.
     */
    public const ENV_TOKEN = 'IRONCART_SCAN_UPLOAD_TOKEN';
    public const ENV_ENABLED = 'IRONCART_SCAN_UPLOAD_ENABLED';

    /**
     * Default production endpoint. Mirrored in `etc/config.xml` so the
     * Magento config provider returns the same value when no admin
     * override is set.
     */
    public const DEFAULT_ENDPOINT = 'https://ironcart.dev/api/scan/ingest';

    /**
     * Default allowlisted host. The {@see CurlUploadClient} rejects any
     * URL whose host does not match this value.
     */
    public const DEFAULT_ALLOWED_HOST = 'ironcart.dev';

    /**
     * @param ScanSession|null $scanSession Optional — when provided,
     *                                      a non-null {@see ScanSession::uploadTokenOverride()}
     *                                      wins over both the env var
     *                                      and `core_config_data`. Left
     *                                      nullable so existing test
     *                                      fixtures and third-party DI
     *                                      overrides keep working.
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly ?ScanSession $scanSession = null
    ) {
    }

    public function isEnabled(): bool
    {
        // Env-var enable mirrors Magento's `isSetFlag()` truthiness:
        // accept "1", "true", "yes", "on" (case-insensitive). Anything
        // else — including unset — falls through to the admin flag.
        // No CLI override path here: enabling upload is a deliberate
        // posture decision; we want the operator who passes `--upload`
        // on the CLI to ALSO have flipped the admin/env toggle, so a
        // stray scheduler job can't smuggle in opt-out behavior.
        $envEnabled = getenv(self::ENV_ENABLED);
        if (is_string($envEnabled) && $envEnabled !== '') {
            $normalized = strtolower(trim($envEnabled));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
            // Unknown shape — fall through to admin config rather than
            // silently honour a typo.
        }

        return $this->scopeConfig->isSetFlag(self::PATH_ENABLED);
    }

    /**
     * Return the resolved upload token, or an empty string when no token
     * is configured at any layer. The runner treats empty-string as
     * misconfiguration.
     *
     * Resolution order:
     *
     *   1. {@see ScanSession::uploadTokenOverride()} (CLI `--upload-token=`)
     *   2. Env var `IRONCART_SCAN_UPLOAD_TOKEN` (plaintext)
     *   3. `core_config_data` (`ironcart_scan/upload/token`), decrypted
     */
    public function token(): string
    {
        // 1. CLI override.
        $override = $this->scanSession?->uploadTokenOverride();
        if (is_string($override) && $override !== '') {
            return $override;
        }

        // 2. Env var (plaintext — Magento Cloud / Docker / CI path).
        $envToken = getenv(self::ENV_TOKEN);
        if (is_string($envToken) && $envToken !== '') {
            return $envToken;
        }

        // 3. core_config_data (encrypted at rest via the encryptor).
        $raw = $this->scopeConfig->getValue(self::PATH_TOKEN);
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        // Magento stores encrypted-backend values as ciphertext; the
        // accessor returns the ciphertext, not the plaintext. We decrypt
        // here so the token never sits in memory in plaintext outside
        // this single call site.
        $plain = $this->encryptor->decrypt($raw);
        return is_string($plain) ? $plain : '';
    }

    public function endpoint(): string
    {
        $value = $this->scopeConfig->getValue(self::PATH_ENDPOINT);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return self::DEFAULT_ENDPOINT;
    }

    /**
     * Host the upload client will pin to. Always read from admin config
     * (with a built-in default) so QA / staging can target a non-
     * production host without rebuilding the module.
     */
    public function allowedHost(): string
    {
        $value = $this->scopeConfig->getValue(self::PATH_ALLOWED_HOST);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return self::DEFAULT_ALLOWED_HOST;
    }
}
