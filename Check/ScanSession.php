<?php

/**
 * IronCart_Scan — per-run scan session state.
 *
 * Mutable, request-scoped value holder for operator-controlled flags forwarded
 * from `bin/magento ironcart:scan`. Started life as the `--include-usernames`
 * opt-in (used by the admin-posture check pack to gate PII); v6 (#123) adds
 * the multi-store license/upload-token CLI overrides — one-shot license blob
 * and upload token values that take precedence over env vars and
 * `core_config_data` for the duration of a single scan, without ever
 * persisting to `core_config_data`.
 *
 * `ScanCommand` sets the flags from CLI input on the DI-injected singleton
 * before invoking {@see CheckRegistry::runAll()}; checks read the flag values
 * during {@see CheckInterface::run()}. This indirection keeps the check
 * contract arg-free while still letting individual checks honour per-run
 * operator preferences.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check;

/**
 * Mutable holder for the operator-supplied flags of a single scan run.
 *
 * Registered as a Magento DI singleton so every check sees the same instance.
 * Defaults are deliberately safe (no PII) — opt-in only.
 */
class ScanSession
{
    private bool $includeUsernames = false;

    /**
     * One-shot license blob supplied via `--license=...` on the CLI. `null`
     * means "no CLI override; fall back to env var, then `core_config_data`".
     * Empty-string is treated identically to `null` so a caller can pass
     * through getOption() results verbatim. Never persisted.
     */
    private ?string $licenseOverride = null;

    /**
     * One-shot upload token supplied via `--upload-token=...` on the CLI.
     * Same null/empty-string semantics as {@see $licenseOverride}.
     */
    private ?string $uploadTokenOverride = null;

    public function setIncludeUsernames(bool $value): void
    {
        $this->includeUsernames = $value;
    }

    public function includeUsernames(): bool
    {
        return $this->includeUsernames;
    }

    /**
     * Set the one-shot CLI license-blob override. Pass `null` (or empty
     * string) to clear. The value is treated as the raw on-the-wire blob
     * (`<base64url-json>.<base64url-sig>`); the verifier MUST still validate
     * it before any callsite trusts it.
     */
    public function setLicenseOverride(?string $blob): void
    {
        $this->licenseOverride = ($blob === null || $blob === '') ? null : $blob;
    }

    /**
     * The CLI license-blob override, or `null` when no override is in
     * effect. Consumers compose this with env-var + admin-config fallback
     * — see {@see \IronCart\Scan\Check\License\LicenseConfig::blob()}.
     */
    public function licenseOverride(): ?string
    {
        return $this->licenseOverride;
    }

    /**
     * Set the one-shot CLI upload-token override. Pass `null` (or empty
     * string) to clear.
     */
    public function setUploadTokenOverride(?string $token): void
    {
        $this->uploadTokenOverride = ($token === null || $token === '') ? null : $token;
    }

    /**
     * The CLI upload-token override, or `null` when no override is in
     * effect. Consumers compose this with env-var + admin-config fallback
     * — see {@see \IronCart\Scan\Check\Upload\UploadConfig::token()}.
     */
    public function uploadTokenOverride(): ?string
    {
        return $this->uploadTokenOverride;
    }
}
