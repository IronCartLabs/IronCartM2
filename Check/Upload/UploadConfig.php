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
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Upload;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Read-only view of the `ironcart_scan/upload/*` admin config.
 */
class UploadConfig
{
    public const PATH_ENABLED = 'ironcart_scan/upload/enabled';
    public const PATH_TOKEN = 'ironcart_scan/upload/token';
    public const PATH_ENDPOINT = 'ironcart_scan/upload/endpoint';
    public const PATH_ALLOWED_HOST = 'ironcart_scan/upload/allowed_host';

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

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_ENABLED);
    }

    /**
     * Return the decrypted upload token, or an empty string when no token
     * is configured. The runner treats empty-string as misconfiguration.
     */
    public function token(): string
    {
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
