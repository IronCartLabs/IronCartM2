<?php

/**
 * IronCart_Scan — compiled-in Ed25519 license-signing public key.
 *
 * Counterpart to {@see \IronCart\Scan\Check\License\LicenseVerifier}. The
 * pro-tier license blob is signed by the hosted backend (IronCartWeb
 * `lib/license/sign.ts`) using an Ed25519 keypair whose private half
 * never leaves Vercel. The matching public half ships baked into this
 * module so {@see LicenseVerifier} can validate blobs entirely offline
 * — no outbound network call, no second secret on the merchant box.
 *
 * Rotation contract:
 *
 *   1. The hosted signer publishes a new {@code sigVersion} (see
 *      `lib/license/sign.ts::SIG_VERSION_V1`).
 *   2. The pro-module release pipeline overwrites {@see PUBLIC_KEY_BASE64}
 *      with the new public-key base64 string and ships a new module
 *      version.
 *   3. For the rotation overlap window (30 days), the hosted signer
 *      accepts both `sigVersion` values; the module ships with the
 *      newer key only because it verifies against ONE public key.
 *
 * Build-time stamping:
 *
 *   - The release pipeline copies the production public key from the
 *     `IRONCART_LICENSE_SIGNING_PUBLIC_KEY` env var (Vercel User scope,
 *     see `docs/license-signing-setup.md` in IronCartWeb) into
 *     {@see PUBLIC_KEY_BASE64} via a `sed` replacement just before
 *     `composer archive` / GitHub Release artifact creation.
 *   - The repo's source ships with an empty placeholder so the public
 *     key never lives in version control. An empty value is treated by
 *     {@see LicenseVerifier} as "no key compiled in" — every license
 *     blob is then rejected with {@see LicenseVerifier::REASON_NO_KEY}.
 *   - Unit tests inject a test keypair directly via the
 *     {@see LicenseVerifier::__construct} `$publicKeyBase64` argument;
 *     they never touch this constant.
 *
 * Why a constant (not env, not DI from `system.xml`):
 *
 *   - Operators must never be able to swap the public key by editing
 *     admin config or env. Anyone with shell access on the store could
 *     otherwise paste an attacker-controlled key + a forged license
 *     blob and unlock pro-tier features. The trust anchor MUST live in
 *     read-only module source so a tampered store box is reproducible
 *     from `composer.lock` + the module package.
 *   - The release-artifact stamp is the SAME operation that brands the
 *     module with its version number (`extra.module-version` in
 *     `composer.json`); putting them in one pipeline step keeps the
 *     "what version contains which public key" question answerable from
 *     the GitHub Release notes alone.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 *
 * @see https://github.com/IronCartLabs/IronCartM2/issues/103
 * @see \IronCart\Scan\Check\License\LicenseVerifier
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\License;

/**
 * Constants-only holder for the compiled-in production public key.
 *
 * Marked {@code final} + private constructor (pre-PHP-8.1 enum idiom; the
 * same pattern as {@see \IronCart\Scan\Report\Severity}). The Magento2
 * coding standard's {@code FinalImplementation} sniff is excluded for
 * this pattern in {@see phpcs.xml.dist}.
 */
final class LicensePublicKey
{
    /**
     * Base64-encoded Ed25519 public key (32 raw bytes decode to a
     * production-ready key). Empty in source — the release pipeline
     * stamps the real value just before publishing the GitHub Release
     * tarball.
     *
     * Empty value semantics: {@see LicenseVerifier} refuses to verify
     * any blob and returns {@see LicenseVerifier::REASON_NO_KEY}. This
     * keeps a dev build of the module safe — pasting a license blob
     * into an unstamped build never grants pro-tier upload behavior.
     *
     * @var string
     */
    public const PUBLIC_KEY_BASE64 = '';

    private function __construct()
    {
    }
}
