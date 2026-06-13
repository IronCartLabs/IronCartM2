# Changelog

All notable changes to `ironcartlabs/magento-scan` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.6.0] - 2026-06-13

Platform-widening release for Magento 2.4.9 (GA 2026-05-12) and PHP
8.5. Strictly additive over `1.5.1` from the merchant install
perspective: no removed or renamed CLI / class / config / DI surface,
no behavioural changes, no new outbound network surface. Every
previously supported cell (Magento 2.4.4 – 2.4.8 × PHP 8.1 – 8.4)
stays installable.

### Changed

- **`composer.json` `require.php`** widened from `~8.1.0||~8.2.0||~8.3.0||~8.4.0` to `~8.1.0||~8.2.0||~8.3.0||~8.4.0||~8.5.0` ([#194](https://github.com/IronCartLabs/IronCartM2/issues/194)). Magento 2.4.9 dropped PHP 8.1 / 8.2 from its own platform constraint; the module keeps both arms for the older Magento lines.
- **`etc/module.xml` `setup_version`** bumped from `1.5.1` to `1.6.0`. Read at runtime to construct the `IronCart-Scan/<version>` User-Agent on outbound HTTP surfaces.
- **`composer.json` `extra.module-version`** bumped from `1.5.1` to `1.6.0`. Kept in sync with `etc/module.xml`.
- **`README.md`** install requirement line now lists PHP 8.5; Compatibility section adds the Magento 2.4.9 row (PHP 8.3 / 8.4 / 8.5) and the PHP 8.5 matrix column.

### Notes

- **`require.magento/framework` is intentionally unchanged.** Verified against the [`magento/magento2` `2.4.9` tag](https://github.com/magento/magento2/blob/2.4.9/composer.json): Magento 2.4.9 ships `magento/framework` **103.0.9** (not a new major), which the existing `^103.0` arm already satisfies. The PHP constraint was the only installation blocker for 2.4.9.
- No `Check/`, `Console/`, `Controller/`, `Model/`, or `etc/di.xml` source touched. The runtime is unchanged: every check class, ACL resource, DI binding, cron job, and CLI command behaves byte-identically to `1.5.1`.
- The whole module PHP surface (`php -l` recursive parse check + phpcs Magento2 via `magento/magento-coding-standard` ^39, the first major to declare PHP 8.5) was validated under a PHP 8.5 runtime, mirroring the PHP 8.4 validation contract from [#151](https://github.com/IronCartLabs/IronCartM2/issues/151) / [#161](https://github.com/IronCartLabs/IronCartM2/issues/161). No PHP 8.5 deprecations or parse errors found.
- Magento 2.4.9 CI matrix cells (PHP 8.4 / 8.5) land separately via [#196](https://github.com/IronCartLabs/IronCartM2/issues/196), unblocked by this release's constraint widening.
- Tracking epic: [IronCartLabs/IronCartWeb#884](https://github.com/IronCartLabs/IronCartWeb/issues/884).

### Install

```
composer require ironcartlabs/magento-scan:^1.6
bin/magento module:enable IronCart_Scan
bin/magento setup:upgrade
```

## [1.5.1] - 2026-06-12

Critical packaging fix. `composer require ironcartlabs/magento-scan` on
a standard Magento 2 project (all releases `v1.0.0`–`v1.5.0`) could
crash the site and CLI with `Component 'IronCart_Scan' has been already
defined`. **All merchants should upgrade to `>=1.5.1`** — see the
remediation steps below.

### Fixed

- **Duplicate `IronCart_Scan` registration breaks composer installs — legacy `extra.map` removed from `composer.json`** ([#192](https://github.com/IronCartLabs/IronCartM2/issues/192)). Since the bootstrap commit, `composer.json` shipped two registration mechanisms at once: the standard vendor autoload (`autoload.files: ["registration.php"]` + PSR-4) **and** a legacy `extra.map: [["*", "IronCart/Scan"]]`. Magento projects ship the composer plugin `magento/magento-composer-installer`, which acts on `type: magento2-module` packages carrying a `map` and copies the whole package into `app/code/IronCart/Scan` during `composer install`. Magento then registers the module from `vendor/` (composer autoload) *and* from `app/code/` (`NonComposerComponentRegistration`), and `ComponentRegistrar` throws `Component 'IronCart_Scan' has been already defined` — site and CLI both down. Field-confirmed on a production merchant site. The `extra.map` entry is removed; a composer install now registers the module exactly once, from `vendor/ironcartlabs/magento-scan`.

  **Merchant remediation:**

  1. Upgrade to `>=1.5.1`: `composer require ironcartlabs/magento-scan:^1.5.1` (or `composer update ironcartlabs/magento-scan`).
  2. Delete any stale copy left behind by earlier versions: `rm -rf app/code/IronCart/Scan`.
  3. Redeploy / `bin/magento setup:upgrade`.

  Note: on `<=1.5.0`, deleting `app/code/IronCart/Scan` alone is **not** a durable fix — `composer install` re-creates the copy on every fresh build/release directory. The upgrade to `>=1.5.1` is required.

- **Concurrent-drain race between Magento core's `consumers_runner` and the module-owned `ironcart_scan_consumer_drain` cron** ([#155](https://github.com/IronCartLabs/IronCartM2/issues/155)). `IronCart\Scan\Model\ScanRunConsumer::process()` now try-locks the same `ironcart_scan_consumer_drain` named lock that the cron job uses (0s timeout). If a competing consumer process holds the lock, the handler re-publishes its message back to the `ironcart.scan.run` topic and ACKs cleanly so the queue framework does not mark it failed; only one process executes `checkRegistry->runAll()` at a time across all drivers. The stuck-QUEUED admin notice (`Model/Notification/ConsumerStalledMessage::getText()`) no longer recommends enabling the `consumers_runner` cron group as a remediation — `bin/magento cron:install` is the canonical fix because the module-owned drain job is now race-safe regardless of operator-side `consumers_runner` config.

### Changed

- **`etc/module.xml` `setup_version`** bumped from `1.5.0` to `1.5.1`. Read at runtime to construct the `IronCart-Scan/<version>` User-Agent on outbound HTTP surfaces.
- **`composer.json` `extra.module-version`** bumped from `1.5.0` to `1.5.1`. Kept in sync with `etc/module.xml`.

### Notes

- The packaging fix is composer-manifest-only; the #155 consumer-drain fix is the only PHP source change since `1.5.0`. No removed or renamed CLI / class / config / DI surface, no new outbound network surface.
- Why CI never caught the duplicate registration: the docker sandbox installs the module by copying source straight into `app/code`, so the vendor-vs-`app/code` collision cannot occur there. A regression guard is tracked as follow-up on [#192](https://github.com/IronCartLabs/IronCartM2/issues/192).

### Install

```
composer require ironcartlabs/magento-scan:^1.5.1
bin/magento module:enable IronCart_Scan
bin/magento setup:upgrade
```

## [1.5.0] - 2026-05-23

Platform-widening release. Strictly additive over `1.4.0` from the
merchant install perspective: no removed or renamed CLI / class /
config / DI surface, no behavioural changes, no new outbound network
surface. Adds PHP 8.4 and Magento 2.4.8 (framework 104.x) to the
supported platform matrix so merchants on the latest Adobe-supported
stack can `composer require ironcartlabs/magento-scan` without the
platform-check rejection.

### Changed

- **`composer.json` `require.php`** widened from `~8.1.0||~8.2.0||~8.3.0` to `~8.1.0||~8.2.0||~8.3.0||~8.4.0` ([#154](https://github.com/IronCartLabs/IronCartM2/issues/154)). PHP 8.1 / 8.2 / 8.3 remain supported; attrition off the older lines is a separate decision once merchant telemetry justifies it.
- **`composer.json` `require.magento/framework`** widened from `^103.0` to `^103.0 || ^104.0` ([#154](https://github.com/IronCartLabs/IronCartM2/issues/154)). Magento 2.4.8 ships `magento/framework` on the 104.x major; 103.x (Magento 2.4.4 – 2.4.7) stays supported.
- **`etc/module.xml` `setup_version`** bumped from `1.4.0` to `1.5.0`. Read at runtime to construct the `IronCart-Scan/<version>` User-Agent on outbound HTTP surfaces.
- **`composer.json` `extra.module-version`** bumped from `1.4.0` to `1.5.0`. Kept in sync with `etc/module.xml`.
- **`README.md`** install requirement line now lists PHP 8.4; Compatibility section adds the Magento 2.4.8 / PHP 8.4 CI row and a per-version support matrix table.

### Notes

- No `Check/`, `Console/`, `Controller/`, `Model/`, or `etc/di.xml` source touched. The runtime is unchanged: every check class, ACL resource, DI binding, cron job, and CLI command behaves byte-identically to `1.4.0`.
- The 2.4.8 × PHP 8.4 cell is already green on the `integration` matrix in `.github/workflows/ci.yml` against the v0 report shape and the IC-072 composer-lock baseline; this release only changes the published platform-version constraints.
- Tracking epic: [IronCartLabs/IronCartWeb#884](https://github.com/IronCartLabs/IronCartWeb/issues/884).

### Install

```
composer require ironcartlabs/magento-scan:^1.5
bin/magento module:enable IronCart_Scan
bin/magento setup:upgrade
```

## [1.4.0] - 2026-05-19

The v6 + Recon Phase 7 module wave. Folds the Hyvä-specific check pack,
the PWA Studio check pack, and the Recon Phase 7 module-side checks
(file-integrity baseline, env.php Recon-grade permissions sweep, recent
admin-actions audit) into one tag on top of `1.3.0`. Strictly additive
from the merchant install perspective — no removed or renamed CLI /
class / config surface; upgrades are `composer update
ironcartlabs/magento-scan && bin/magento setup:upgrade`. Non-Hyvä /
non-PWA / free-tier installs see byte-identical scan output to `1.3.0`
because the new Hyvä, PWA-Studio, and Recon checks all short-circuit to
zero findings on the detector-says-no / no-license path.

> **Packaging note (per resolved Recon 7.0 decision, [IronCartWeb#1184](https://github.com/IronCartLabs/IronCartWeb/issues/1184)).**
> Recon Phase 7 ships inside the existing `ironcartlabs/magento-scan`
> Packagist package — no separate `magento-recon` repo, no second
> Packagist submission. Recon-only checks are runtime-gated via the
> Ed25519 `LicenseConfig` plumbing landed in v1.3.0 (#111); free-tier
> installs see them silently no-op.

### Added — Hyvä check pack

- **`Check/Hyva/HyvaDetector`** ([#125](https://github.com/IronCartLabs/IronCartM2/issues/125)). Shared, DI-singleton detector composing `ModuleListInterface` (looks for the `Hyva_Theme` module) and the existing `ComposerLockReader` (looks for `hyva-themes/*` packages in `composer.lock`). Either signal flips the storefront into Hyvä mode for the IC-9xx pack; the detection record is memoised for the lifetime of the scan run so the three Hyvä-aware checks pay the lookup cost once.
- **IC-910 — Tailwind / postcss config exposed under `pub/static`** ([#125](https://github.com/IronCartLabs/IronCartM2/issues/125)). Walks `<magento_root>/pub/static/frontend/<vendor>/<theme>/` two levels deep looking for `tailwind.config.js`, `tailwind.source.css`, and `postcss.config.js` (both at the theme root and inside the `tailwind/` subdir Hyvä's default theme uses). Severity MEDIUM; remediation at `https://ironcart.dev/docs/checks/IC-910`. Read-only filesystem walk, bounded so a wrecked deploy with hundreds of stale theme directories does not blow the scan timeout.
- **IC-911 — Hyvä Checkout CSP whitelist hash drift** ([#125](https://github.com/IronCartLabs/IronCartM2/issues/125)). Parses the merchant's `app/etc/csp_whitelist.xml` for every `sha256` hash under `<policy id="script-src">` and compares against the bundled `etc/manifests/hyva-checkout/<version>.json` for the installed `hyva-themes/magento2-hyva-checkout` version. Hashes whitelisted but not in the manifest surface as MEDIUM findings. When the installed checkout version is newer than every bundled manifest, IC-911 emits a single LOW informational finding pointing at the manifest-refresh path (`bin/refresh-osv-snapshot.php` cadence). No network call — the manifest ships in-repo. Manifest seed at `etc/manifests/hyva-checkout/1.1.16.json` (placeholder hashes; first real Hyvä Checkout release the manifest covers will replace them).
- **IC-912 — Hyvä module version drift** ([#125](https://github.com/IronCartLabs/IronCartM2/issues/125)). Cross-references every installed `hyva-themes/*` composer package against the bundled `etc/manifests/hyva-modules/min-versions.json` floor manifest. Packages below the floor emit one finding each; severity is HIGH when the floor row is tagged `"security": true` (set because of a published advisory) and MEDIUM otherwise. Packages with no manifest row are silently skipped — IC-002 / IC-060 already provide CVE-driven coverage for the long tail. No network call; refresh path is the same `bin/refresh-osv-snapshot.php` flow as IC-002.
- **IC-913 — Hyvä template references Alpine.js from a public CDN** ([#129](https://github.com/IronCartLabs/IronCartM2/issues/129)). Walks `app/design/frontend/` and `vendor/hyva-themes/` (bounded to 6 directory levels and 2000 files per root) for `.phtml` / `.html` templates that load Alpine.js from a public JS CDN (jsdelivr, unpkg, cdnjs, esm.sh, jspm, skypack). Severity MEDIUM; one finding per scan listing every match. First-party / vendored Alpine bundles are not flagged. Read-only filesystem walk; no network call.

### Added — PWA Studio check pack

- **`Check/PwaStudio/PwaStudioDetector`** ([#129](https://github.com/IronCartLabs/IronCartM2/issues/129)). Shared, DI-singleton detector composing the existing `ComposerLockReader` (looks for `magento/pwa` / `magento/module-pwa`), a `package.json` reader (looks for `@magento/pwa-studio` / `@magento/venia-ui` / `@magento/peregrine` / `@magento/venia-concept` in `dependencies` / `devDependencies` / `peerDependencies`), and a filesystem-marker probe (`pwa-studio.config.json`, `venia.config.json`, `packages/venia-concept/`). Any single signal flips the storefront into PWA mode for the IC-92x pack; the detection record is memoised so the three PWA-aware checks pay the lookup cost once.
- **IC-921 — GraphQL introspection enabled in production** ([#129](https://github.com/IronCartLabs/IronCartM2/issues/129)). Reads `graphql/validation/disable_introspection` via `ScopeConfigInterface` and `MAGE_MODE` via `State::getMode()`. Emits one MEDIUM finding when introspection is enabled (config `0` / unset) on a production-mode Magento install. Skipped silently on developer / default modes — introspection is desirable there.
- **IC-922 — GraphQL query depth / complexity limits** ([#129](https://github.com/IronCartLabs/IronCartM2/issues/129)). Reads `graphql/validation/maximum_query_depth` and `graphql/validation/maximum_query_complexity`. Emits one MEDIUM finding (listing every gap in the `evidence.gaps` array) when either knob is unset / non-numeric, ≤ 0, or above the safe ceilings (depth > 20, complexity > 300, aligned with Magento 2.4.7 shipping defaults).
- **IC-923 — GraphQL CORS allows wildcard origin** ([#129](https://github.com/IronCartLabs/IronCartM2/issues/129)). Reads `web/graphql/cors_allowed_origins` and flags the literal `*`, the literal `null` origin, and `*.<domain>` subdomain wildcards. Severity HIGH because PWA Studio's Apollo client routinely sends `Authorization: Bearer <customer-token>` headers — a wildcard origin lets any third-party site mount credentialed GraphQL requests from the visitor's browser. Skipped silently when the config is unset (Magento defaults to no CORS exposure).

### Added — Recon Phase 7 module-side checks

- **IC-073 / IC-074 — file-integrity baseline (Recon Phase 7.1)** ([IronCartLabs/IronCartWeb#1185](https://github.com/IronCartLabs/IronCartWeb/issues/1185), [#136](https://github.com/IronCartLabs/IronCartM2/pull/136)). New `Check/Integrity/FileHashCheck` diffs the live `app/code/**`, `app/etc/**`, and `vendor/magento/**` tree against a locally-built SHA-256 baseline at `var/recon/baseline.json`. Severity ladder: HIGH for `app/code/**` + `app/etc/**` drift, MEDIUM for `vendor/magento/**` drift, CRITICAL mass-tampering summary above 200 altered files. IC-074 LOW informational when no baseline exists yet. Pro-only: short-circuits to zero findings when `LicenseConfig::parsedClaims()` returns null, so free-tier installs see nothing from this check. New `bin/magento recon:integrity:rebaseline` CLI (gated by the new `IronCart_Scan::recon_integrity_rebaseline` ACL resource — distinct from `IronCart_Scan::run` so a scheduled-scan operator cannot silence the integrity check by re-baselining) regenerates the baseline; the command refuses to run without a verified Pro license so a compromised free-tier install cannot legitimise tampering. Bundled ignore whitelist at `etc/integrity-ignore.json` (prefixes `var/`, `generated/`, `pub/static/`, `pub/media/`, `.git/`; exact `app/etc/env.php`, `app/etc/config.php`). Read-only against the merchant filesystem; the only on-disk write is `var/recon/baseline.json`, touched exclusively by the rebaseline command. No outbound network calls.
- **IC-200..IC-205 — `Check/Integrity/EnvPhpPermissionsCheck` (Recon Phase 7.2)** ([IronCartLabs/IronCartWeb#1186](https://github.com/IronCartLabs/IronCartWeb/issues/1186), [#134](https://github.com/IronCartLabs/IronCartM2/pull/134)). Single check class emits up to six HIGH-severity findings from a Recon-grade `app/etc/env.php` sweep: file mode not `0640`-or-stricter (IC-200), owner is `root` or a known webserver user (IC-201), `env.php` is a symlink (IC-202), `crypt.key` matches a documented default value (IC-203), a `db.connection.*` entry has an empty password (IC-204), and `session.save = 'files'` with no explicit `save_path` (IC-205). Goes beyond the free-tier IC-030/IC-031/IC-032 by enforcing the stricter posture an outside-the-store scanner cannot observe — IC-030 only flags world-readable mode bits, IC-031 only flags the webserver-user owner case, and IC-032 only catches absent / string-placeholder crypt keys. Privacy invariant: never copies key bytes, password values, or session paths into the evidence payload — only structural facts (`present`, `default_match`, mode bits, owner name). Read-only; degrades with an `info` finding when env.php is missing or unreadable. `etc/di.xml` appends one new `IC-200` entry; the single class returns findings for all six IDs.
- **IC-014 — recent admin-actions audit (Recon Phase 7.3)** ([IronCartLabs/IronCartWeb#1187](https://github.com/IronCartLabs/IronCartWeb/issues/1187), [#133](https://github.com/IronCartLabs/IronCartM2/pull/133)). New `Check\AdminAudit\RecentActionsCheck` reads the last 24h of activity from `admin_user` (created / modified timestamps), `admin_passwords` (`last_updated`), and `admin_user_session` (login IPs + `updated_at`) and emits sub-findings for new admin users (`IC-014.new-admin`, HIGH), recently modified existing admin rows as a coarse role-change proxy (`IC-014.role-change`, MEDIUM), password resets (`IC-014.password-reset`, MEDIUM), the set of `/24` / `/48` login-IP prefixes (`IC-014.login-ips`, INFO), and logins outside an operator-configured business-hours window (`IC-014.off-hours`, HIGH). PII contract: usernames are SHA-256 hashed (first 16 hex chars) and IPs truncated to network prefixes by default; plaintext usernames only surface when the operator passes the existing `include-usernames` flag. Off-hours detection is suppressed until the operator sets `admin/security/business_hours_start` and `admin/security/business_hours_end` (24-hour ints) — default is "no opinion". Read-only: no raw SQL, no writes, no outbound network.

### Added — Recon push-event recipient (ironcart.dev side)

- **Module push events now have a server-side recipient.** The Recon Phase 7.4 ingest endpoint (`POST /api/recon/module-event`, [IronCartLabs/IronCartWeb#1188](https://github.com/IronCartLabs/IronCartWeb/issues/1188)) shipped on the ironcart.dev side this slot. The module does not yet emit push events from `1.4.0` — that producer ships in a follow-up minor release once the HMAC secret distribution path is finalised. Documented here so operators auditing the v6 / Recon-7 wave know the inbound side exists.

### Changed — CI

- **CI integration matrix extended with Hyvä + PWA Studio sandbox cells** ([#131](https://github.com/IronCartLabs/IronCartM2/issues/131), [#132](https://github.com/IronCartLabs/IronCartM2/pull/132)). Two new jobs in `.github/workflows/ci.yml` (`integration-hyva` and `integration-pwa`) boot the existing docker-compose Magento sandbox against the 2.4.7-p5 / PHP 8.3 baseline, layer in Hyvä-detection signals (composer `hyva-themes/magento2-theme-module` + a planted CDN-Alpine fixture template) and PWA-Studio-detection fixtures (a non-installed `package.json` + `pwa-studio.config.json` marker plus pre-configured GraphQL knobs), then drive new `tests/sandbox/hyva-integration.php` and `tests/sandbox/pwa-integration.php` drivers that assert IC-910..IC-913 and IC-921..IC-923 fire end-to-end against a real Magento boot. Gated on the same `INTEGRATION_ENABLED` repo variable as the default Luma `integration` cell. No `Check/` source touched.

### Changed

- **`etc/module.xml` `setup_version`** bumped from `1.3.0` to `1.4.0`. Read at runtime to construct the `IronCart-Scan/<version>` User-Agent on outbound HTTP surfaces.
- **`composer.json` `extra.module-version`** bumped from `1.3.0` to `1.4.0`. Kept in sync with `etc/module.xml`.
- **`etc/di.xml`** appends entries to the `CheckRegistry` `checks` argument across the v6 + Recon-7 wave: `IC-910`, `IC-911`, `IC-912`, `IC-913` (Hyvä); `IC-921`, `IC-922`, `IC-923` (PWA Studio); `IC-073` (Recon file-integrity, Pro-gated); `IC-200` (Recon env.php sweep, single class returns IC-200..IC-205); `IC-014` (Recon admin-actions audit). Declares `HyvaDetector` and `PwaStudioDetector` as `shared="true"`. Existing entries are unchanged — the wave is strictly additive.

### Notes

- Strictly additive — no removed or renamed CLI commands, class names, config keys, or DI bindings from the v1.3.0 baseline.
- Free OSS check pack stays open-source. The paywall axis (delivery + enrichment via the Recon subscription) is unchanged; IC-910..IC-913 + IC-921..IC-923 all ship under the MIT module like every other free-tier check. The Recon-specific checks (IC-073 file-integrity baseline, IC-200..IC-205 env.php sweep, IC-014 admin-actions audit) are present in the same MIT codebase but runtime-gated by the Ed25519 `LicenseConfig` parsed-claims check — free-tier installs see them silently no-op.
- No new outbound network calls from the module. The Hyvä / PWA / Recon checks are pure config / filesystem / DB reads; the Hyvä manifests (Checkout CSP hash + module min-version) ship in-repo and are refreshed on the OSV-snapshot cadence.
- Compat matrix unchanged: Magento 2.4.4–2.4.7 × PHP 8.1–8.3 stays green.
- Remediation guide stubs (`docs/checks/IC-014`, `docs/checks/IC-073`, `docs/checks/IC-200`..`IC-205`, `docs/checks/IC-910`..`IC-913`, `docs/checks/IC-921`..`IC-923`) land in IronCartWeb as a follow-up `agent:content` ticket once the check IDs are locked.
- Tracking epics: [IronCartLabs/IronCartWeb#884](https://github.com/IronCartLabs/IronCartWeb/issues/884) ("v6 — Hyvä/PWA Studio specific checks") and [IronCartLabs/IronCartWeb#186](https://github.com/IronCartLabs/IronCartWeb/issues/186) ("Recon Phase 7 — Optional Magento module").

### Install

```
composer require ironcartlabs/magento-scan:^1.4
bin/magento module:enable IronCart_Scan
bin/magento setup:upgrade
```

## [1.3.0] - 2026-05-18

The v5 module wave. Folds Pro entitlement plumbing, Adobe EQP-readiness
coverage, the deprecation-taxonomy revert (IC-060 + IC-070..073 stay in
Free OSS forever), a batch of admin-UI fixes, and the no-pivot copy
scrub into one tag. Strictly additive on top of `1.2.0` from the
merchant install perspective — no removed or renamed CLI / class /
config surface; upgrades are `composer update ironcartlabs/magento-scan
&& bin/magento setup:upgrade`.

### Added — Pro entitlement plumbing

- **`ironcart_scan/license/blob` admin config + Ed25519 verifier** ([#111](https://github.com/IronCartLabs/IronCartM2/pull/111), closes [#103](https://github.com/IronCartLabs/IronCartM2/issues/103)). New admin field under **Stores → Configuration → Ironcart → Scan** accepts an opaque license blob; the runtime verifier validates the Ed25519 signature against the bundled `ironcart.dev` public key and exposes the parsed entitlement (tier, expiry, store fingerprint) to the rest of the module. Free OSS continues to run identically when no blob is present — the check pack is unchanged regardless of tier.
- **Module-upgrade Pro callout after free-tier upload** ([#112](https://github.com/IronCartLabs/IronCartM2/pull/112), closes [#104](https://github.com/IronCartLabs/IronCartM2/issues/104)). After a successful free-tier upload, the CLI / admin surface renders a one-line callout pointing operators at the Recon subscription on `ironcart.dev/pricing`. Suppressed once a valid Pro entitlement is present.

### Added — Adobe EQP coverage

- **EQP gap audit for marketplace-mirror submission** ([#89](https://github.com/IronCartLabs/IronCartM2/pull/89), closes [#81](https://github.com/IronCartLabs/IronCartM2/issues/81)). New `docs/marketplace-eqp-audit.md` enumerates every Adobe EQP rule the module trips (or doesn't), with the per-item fix or documented seam. Re-walked on every release-readiness pass.
- **MEQP suppression of documented `ObjectManager::getInstance()` seams** ([#94](https://github.com/IronCartLabs/IronCartM2/pull/94), closes [#84](https://github.com/IronCartLabs/IronCartM2/issues/84)). Two intentional seams (factory-style ACL gate, schema-version probe) are now annotated with `@SuppressWarnings(PHPMD)` and matching MEQP suppression markers, so marketplace submission no longer flags them.
- **`i18n/en_US.csv` source locale** ([#95](https://github.com/IronCartLabs/IronCartM2/pull/95), closes [#86](https://github.com/IronCartLabs/IronCartM2/issues/86)). Source-locale translation file covering every `__()` call site and every `translate="…"` XML attribute in the module. Adobe Marketplace EQP's `MEQP2.Translation.MissingI18n` rule is a hard submission blocker without this. `bin/check-i18n.php` validator + new `i18n` CI job pin the invariant.
- **`RunScanNowButton` inline-JS refactor + `etc/csp_whitelist.xml`** ([#96](https://github.com/IronCartLabs/IronCartM2/pull/96), closes [#85](https://github.com/IronCartLabs/IronCartM2/issues/85)). The admin "Run scan now" button moves from an `on_click` inline-JS handler to a declarative `data-mage-init` attribute resolved by Magento's `mage/apply` bootstrap. New `etc/csp_whitelist.xml` declares the module's outbound `connect-src` host (`ironcart.dev`) so admins running `system/csp/mode_admin = restrict_mode` keep IC-060 / `--upload` / cron functionality. Zero inline JS emitted by the module.
- **i18n stubs for `de_DE`, `fr_FR`, `es_ES`, `nl_NL`** ([#113](https://github.com/IronCartLabs/IronCartM2/pull/113), closes [#108](https://github.com/IronCartLabs/IronCartM2/issues/108)). Machine-translated locale stubs across the four target languages; `MAGE_DEFAULT_LOCALE=de_DE` (etc.) flips CLI help text + admin grid copy to the active locale. JSON report stays locale-independent for downstream consumers.

### Reverted — Deprecation taxonomy (un-deprecate IC-060, IC-070..073)

- **Un-deprecate IC-060 + IC-070..073** ([#110](https://github.com/IronCartLabs/IronCartM2/pull/110), closes [#102](https://github.com/IronCartLabs/IronCartM2/issues/102), reverts [#90](https://github.com/IronCartLabs/IronCartM2/pull/90)). **IC-060, IC-070, IC-071, IC-072, IC-073 are NOT moving to a paid tier — disregard the v1.4.0 deprecation notice from PR #90.** All 43 checks remain in Free OSS. Pro (Recon subscription) gates delivery and enrichment, never check exclusivity. The deprecation registry (`Check/DeprecationRegistry.php`), admin-grid badge, `--include-deprecated` CLI flag, stderr `[DEPRECATED]` notices, and the `schema_version` `v0`→`v1` bump are all removed. The per-finding `deprecated_in` / `removal_in` / `replacement` / `migration_url` fields are gone; non-deprecated findings remain byte-identical to the v0 schema. Strategy context: [IronCartWeb#1071 amendment](https://github.com/IronCartLabs/IronCartWeb/issues/1071).
- **Remove marketplace-mirror fork artifacts** ([#109](https://github.com/IronCartLabs/IronCartM2/pull/109), closes [#101](https://github.com/IronCartLabs/IronCartM2/issues/101), reverts [#88](https://github.com/IronCartLabs/IronCartM2/pull/88)). Single-package strategy per the v5 amendment: one canonical `ironcartlabs/magento-scan` on Packagist, no sibling package. Drops `package-marketplace/`, `bin/build-marketplace.php`, the `build-marketplace` / `check-marketplace-version` / `clean-marketplace` Makefile targets, and the marketplace branch of `.github/workflows/release-marketplace.yml`. The workflow itself is retained — it now publishes only the OSS Packagist parity tarball on tag (and remains the natural home for a future canonical-source Marketplace tarball if Adobe Marketplace is ever pursued). Underlying technical driver: Packagist's one-package-per-VCS-URL rule.

### Fixed — Admin UI

- **Severity totals: restore per-severity identity in empty circles** ([#98](https://github.com/IronCartLabs/IronCartM2/pull/98), closes [#93](https://github.com/IronCartLabs/IronCartM2/issues/93)). Empty severity circles in the run-detail header no longer collapse to a single all-grey indicator — each severity keeps its own neutral color so operators can scan the row at a glance.
- **Scan detail: "Show all severities" toggle lifts the critical-only filter** ([#99](https://github.com/IronCartLabs/IronCartM2/pull/99), closes [#97](https://github.com/IronCartLabs/IronCartM2/issues/97)). _Superseded later in this release by #116 — see below._ Initial fix made the toggle actually clear the default-critical filter on the findings grid. The toggle is then removed entirely in #116 in favour of standard column filtering.
- **Run Scan Now: scans no longer stuck QUEUED when no queue consumer is running** ([#100](https://github.com/IronCartLabs/IronCartM2/pull/100), closes [#92](https://github.com/IronCartLabs/IronCartM2/issues/92)). The admin notice (severity MAJOR) now fires whenever an `ironcart_scan_run` row stays `queued` past the operator-tunable `ironcart_scan/runtime/consumer_alert_threshold_seconds` threshold (default 60s). Notice clears automatically when the queue drains. Detection-only — operators still need to wire up `bin/magento queue:consumers:start ironcartScanRunConsumer` or `cron_consumers_runner`; the README ["Running scans asynchronously"](README.md#running-scans-asynchronously) section documents both paths.
- **Scan detail grid: populate Detail column at persist time** ([#115](https://github.com/IronCartLabs/IronCartM2/pull/115), closes [#107](https://github.com/IronCartLabs/IronCartM2/issues/107)). `ScanRunConsumer::persistFinding()` now writes a flattened evidence + remediation-URL string into the `detail` column instead of `null`, so the run-detail grid renders something useful per row. `Report\FindingDetailFormatter` is a pure pipeline (no Magento types, unit-CI slice). Returns `null` when both evidence and remediation URL are empty so historical NULL rows keep rendering empty (no migration).
- **Scan detail grid: replace severity toggle with standard column filtering** ([#116](https://github.com/IronCartLabs/IronCartM2/pull/116), closes [#106](https://github.com/IronCartLabs/IronCartM2/issues/106)). Drops the bespoke "Show all severities" / "Show critical only" header button and lets admins narrow findings via the standard Magento severity column filter (`<filter>select</filter>` with `SeverityOptions`). Supports multi-select naturally (critical+high together). Deletes `ShowAllSeveritiesButton.php`, `ShowAllFlag.php`, the `SHOW_ALL_PARAM` plumbing in `ScanFindingDataProvider`, and the BackendSession dependency on `Controller\Adminhtml\Scans\View`. Default behaviour: fresh navigation shows every finding for the run.

### Changed — No-pivot copy scrub

- **README + composer.json description / keywords** ([#114](https://github.com/IronCartLabs/IronCartM2/pull/114), closes [IronCartWeb#1093](https://github.com/IronCartLabs/IronCartWeb/issues/1093) child). Rewrite README + `composer.json` so the module reads as a steady-state product, not an evolving roadmap. Strip all `v0 scaffolding` / `v2 pack` / `v3 adds` / `v4 adds` / "Later stages" framing. README "Security" section reframed as a description of the current outbound surface (not a chronology). Upload section aligned with the canonical story (3 free lifetime uploads + Recon subscription for monitoring). `composer.json` description replaced with a single steady-state sentence; topical keywords array added for Packagist discovery.

### Changed

- **`etc/module.xml` `setup_version`** bumped from `1.2.0` to `1.3.0`. Read at runtime to construct the `IronCart-Scan/<version>` User-Agent on outbound HTTP surfaces.
- **`composer.json` `extra.module-version`** bumped from `1.2.0` to `1.3.0`. Kept in sync with `etc/module.xml`.

### Notes

- No removed or renamed CLI commands, class names, config keys, or DI bindings _from the v1.2.0 baseline_. The deprecation taxonomy (`Check/DeprecationRegistry.php`, `--include-deprecated` flag, `schema_version` `v1`) shipped to `main` between v1.2.0 and v1.3.0 in PR #90 and was reverted in PR #110 before this tag — so installs upgrading from v1.2.0 → v1.3.0 see no exposure to the deprecation surface at all.
- Pro entitlement is a strictly additive surface in this release: the verifier reads `ironcart_scan/license/blob`, exposes the parsed entitlement, and powers the Pro callout in #112. Recon subscription delivery + enrichment surfaces (notification fan-out, fused external scan, team management) ship from the `ironcart.dev` SaaS side — the OSS module remains read-only and free.
- Tracking epic: [IronCartLabs/IronCartWeb#884](https://github.com/IronCartLabs/IronCartWeb/issues/884) (v5 module wave).

### Install

```
composer require ironcartlabs/magento-scan:^1.3
bin/magento module:enable IronCart_Scan
bin/magento setup:upgrade
```

## [1.2.0] - 2026-05-17

Continuous-monitoring minor release. Adds the v4 cron-driven loop on top
of the v3 opt-in `--upload` flow so merchants can keep ironcart.dev's
view of their store posture fresh without remembering to run the CLI by
hand. Strictly additive on top of `1.1.0` — no removed or renamed CLI /
class / config surface, so existing `composer require
ironcartlabs/magento-scan:^1.1` installs are forward-compatible.

### Added

- **Continuous-monitoring cron** ([#64](https://github.com/IronCartLabs/IronCartM2/issues/64)). New `Cron/UploadScan.php` handler, bound from `etc/crontab.xml` as job `ironcart_scan_upload_cron` under group `ironcart_scan`. Drives the same code path as `bin/magento ironcart:scan --upload`. Gated by `ironcart_scan/cron/enabled` (default `0` — hard "opt-in default OFF" invariant per #64), schedule controlled by `ironcart_scan/cron/schedule` (default `0 3 * * *` — daily at 03:00 store-server time). Token is the existing `ironcart_scan/upload/token` — no new credential surface. The merchant store controls when scans run; ironcart.dev never initiates a connection to the merchant store. Logging goes to a dedicated `var/log/ironcart_scan.log` channel separate from the system-wide cron log. Manual trigger: `bin/magento cron:run --group=ironcart_scan`.
- **Admin config: `Stores → Configuration → Ironcart → Scan → Continuous Monitoring`** ([#64](https://github.com/IronCartLabs/IronCartM2/issues/64)). New `cron` group under the existing `ironcart_scan` section in `etc/adminhtml/system.xml` with fields:
  - `Enable scheduled scan + upload` (Yes/No, default **No**) — `ironcart_scan/cron/enabled`.
  - `Schedule (crontab expression)` (text, default `0 3 * * *`) — `ironcart_scan/cron/schedule`. Re-read on every cron tick via `<config_path>` in `etc/crontab.xml`.
- **402 / free-tier exhausted handling** ([#64](https://github.com/IronCartLabs/IronCartM2/issues/64), depends on [IronCartWeb#1004](https://github.com/IronCartLabs/IronCartWeb/issues/1004)). New `UploadClientResult::CATEGORY_QUOTA_EXCEEDED` + `UploadRunnerOutcome::EXIT_QUOTA_EXCEEDED` (exit code `5`). When the ingest endpoint returns 402, the `CurlUploadClient` extracts the `upgrade_url` field from the JSON body (validated to be `https://`) and the runner / cron surface an "upgrade required" message including that URL. The cron schedule row goes `error` so the operator's standard cron-failure monitoring picks it up. The body is otherwise discarded — only the `view_url` field on a 2xx and the `upgrade_url` field on a 402 are ever rendered verbatim.

### Changed

- **`etc/module.xml` `setup_version`** bumped from `1.1.0` to `1.2.0`. Read at runtime to construct the `IronCart-Scan/<version>` User-Agent on outbound HTTP surfaces (IC-060 CVE proxy, IC-080..IC-085 CSP probe, `--upload`).
- **`composer.json` `extra.module-version`** bumped from `1.1.0` to `1.2.0`. Kept in sync with `etc/module.xml`.
- **`etc/di.xml`** wires the v4 cron handler with a virtual `IronCartScanCronLogger` channel pointed at `var/log/ironcart_scan.log`, so the upload outcome is tail-able independently of Magento's system-wide `var/log/cron.log`. The `UploadPayloadBuilder` / `UploadRunner` `moduleVersion` arguments are bumped to `1.2.0` to keep the User-Agent string aligned with the module version.

### Notes

- No removed / renamed CLI commands, class names, config keys, or DI bindings. Upgrade is `composer update ironcartlabs/magento-scan` + `bin/magento setup:upgrade`.
- The new cron is the first scheduled outbound surface in the module; it remains opt-in (off by default) per the v3+ design in the tracking epic. The merchant store accepts no inbound connections from ironcart.dev — the cron is a pull-from-store-and-push-outbound loop.
- Merchant-facing setup guide: <https://ironcart.dev/docs/scanner/continuous-monitoring>.
- Tracking epic: [IronCartLabs/IronCartWeb#884](https://github.com/IronCartLabs/IronCartWeb/issues/884) ("v4 — continuous monitoring").

### Install

```
composer require ironcartlabs/magento-scan:^1.2
bin/magento module:enable IronCart_Scan
bin/magento setup:upgrade
```

## [1.1.0] - 2026-05-17

First stable minor release. Graduates the package out of `-alpha` by folding in the v2 check packs (IC-050..IC-093) and the v3 opt-in upload flag. Strictly additive on top of `1.0.0-alpha.1` — no removed or renamed CLI / class / config surface, so existing `composer require ironcartlabs/magento-scan:^1.0@alpha` installs are forward-compatible. Merchants should move to `composer require ironcartlabs/magento-scan:^1.1`.

### Added

- **IC-050..IC-054 — code-smell pattern scan** ([#52](https://github.com/IronCartLabs/IronCartM2/pull/52)). New `Check/CodeSmell/` pack walks `app/code/` for `eval()`, `passthru`/`shell_exec`/`system`/`exec`, `unserialize` on untrusted input, dynamic `include`/`require`, and the `preg_replace` `/e` modifier. Each match is a separate finding with the offending file, line, and check ID.
- **IC-060 — OSV.dev CVE cross-reference** ([#55](https://github.com/IronCartLabs/IronCartM2/pull/55)). New `Check/Cve/` pack reads the installed composer package set and asks the `ironcart.dev/api/cve` proxy (read-only, no auth, no PII) for known CVEs against each `name@version`. Hardened cURL client mirrors the upload-flag posture: host-pinned, HTTPS-only, `FOLLOWLOCATION=0`. Module User-Agent identifies the module version from `etc/module.xml` `setup_version`.
- **IC-070/IC-071 — core file-integrity (Adobe `magento-core.json`)** ([#54](https://github.com/IronCartLabs/IronCartM2/pull/54)). Open-Source-only self-generated manifest under `etc/manifests/`; the runtime compares on-disk hashes against the bundled manifest for the detected Magento version and flags drift. Adobe Commerce is intentionally out of scope here (covered by Adobe's signed-release tooling).
- **IC-072 — composer-lock SHA1 integrity** ([#56](https://github.com/IronCartLabs/IronCartM2/pull/56)). Verifies that each installed package's distribution `shasum` in `composer.lock` matches the recorded SHA1 in the bundled `etc/manifests/composer-lock/<magento-version>.json`. Catches a tampered or replaced package post-install. The integration cells in `.github/workflows/ci.yml` now assert zero IC-072 HIGH findings on a clean sandbox install.
- **IC-080..IC-085 — CSP posture check pack** ([#53](https://github.com/IronCartLabs/IronCartM2/pull/53)). New `Check/Runtime/Csp/` pack probes the storefront for the presence and shape of a Content-Security-Policy header: missing CSP, `script-src` containing `'unsafe-inline'` / `'unsafe-eval'`, missing `frame-ancestors`, missing `report-uri` / `report-to`, `report-only` left on in production, and base-URL coverage. Loopback-host guard prevents probing private networks accidentally.
- **IC-090..IC-093 — webhook posture check pack** ([#51](https://github.com/IronCartLabs/IronCartM2/pull/51)). New `Check/Webhooks/` pack inspects Magento webhook subscriptions for plaintext (`http://`) destination URLs, private-network destinations (RFC1918 / loopback / link-local), missing signature secrets, and missing retry policy.
- **`bin/magento ironcart:scan --upload`** (v3, [#58](https://github.com/IronCartLabs/IronCartM2/pull/58)). Optional, opt-in HTTPS POST of the scan results to `https://ironcart.dev/api/scan/ingest`. Off by default. Hardened cURL client with host-pinning to `ironcart.dev`, `FOLLOWLOCATION=0`, HTTPS-only protocol set, and full TLS verification. Module-side payload size guards (500 findings / 1000 composer packages). Admin email and operator email are forbidden from the payload tree at every nesting depth; the module refuses to upload if either appears. Admin config exposed under **Stores → Configuration → Ironcart → Scan → Scan Upload**. See [docs/UPLOAD.md](docs/UPLOAD.md). First outbound-network surface in the module; remains opt-in per the v3+ design in the tracking epic.
- **README check-ID table rows for IC-070..IC-073** ([#61](https://github.com/IronCartLabs/IronCartM2/pull/61)). Documents the new file-integrity rows alongside the existing v0/v1 entries.

### Changed

- **CI sandbox docs / Makefile drift fixes** ([#41](https://github.com/IronCartLabs/IronCartM2/pull/41)). Realigned `make` targets and `docs/sandbox.md` with the current `markshust/docker-magento` layout. No runtime impact.
- **`etc/module.xml` `setup_version`** bumped from `0.2.0` to `1.1.0`. This value is read at runtime to construct the `IronCart-Scan/<version>` User-Agent on CSP probes (IC-080..IC-085), CVE proxy calls (IC-060), and `--upload` calls (v3).
- **`composer.json` `extra.module-version`** added with value `1.1.0` so the Composer manifest mirrors the Magento module manifest. Both sources are kept in sync on every minor / patch release.

### Notes

- No removed / renamed CLI commands, class names, config keys, or DI bindings. Upgrade is a `composer update ironcartlabs/magento-scan` + `bin/magento setup:upgrade`.
- All v2 check packs are read-only and offline; only the v3 `--upload` flag opens an outbound HTTP surface, and only when the merchant flips it on under Stores → Configuration → Ironcart → Scan → Scan Upload.
- Tracking epic: [IronCartLabs/IronCartWeb#884](https://github.com/IronCartLabs/IronCartWeb/issues/884) ("v2" + "v3 — module side").

### Install

```
composer require ironcartlabs/magento-scan:^1.1
bin/magento module:enable IronCart_Scan
bin/magento setup:upgrade
```

## [1.0.0-alpha.1] - 2026-05-16

First tagged alpha of the v1 admin-UI loop. Same read-only check pack as the untagged v0 baseline; the new surface area is the persistence + async + admin layer that exposes runs to merchants without dropping to the CLI.

### Added

- **DB schema.** New `db_schema.xml` tables:
  - `ironcart_scan_run` — one row per scan invocation (status, started/finished timestamps, trigger source, totals).
  - `ironcart_scan_finding` — per-check findings linked to a run (severity, check ID, target, message, payload JSON).
- **MessageQueue topic `ironcart.scan.run`.** DB-backed (`db` connection) consumer + publisher wired through `etc/queue.xml`, `etc/communication.xml`, and `etc/queue_consumer.xml`. CLI and admin both publish; the consumer persists results into the new tables.
- **Admin UI — listing.** `Ironcart > Security > Scan Runs` grid (UI component) with status, trigger, finding counts, started/finished columns, sortable + filterable.
- **Admin UI — detail.** Run-detail page showing the per-finding table grouped by severity, with the underlying check ID and payload for each row.
- **Admin UI — run-now button.** "Run scan" action publishes to `ironcart.scan.run`; the row appears immediately with `pending` status and the page polls until the consumer flips it to `completed` / `failed`.
- **ACL.** New `IronCart_Scan::scan_runs` resource gating the admin menu, listing, and run-now action.

### Unchanged

- **Check pack.** Same read-only checks as the pre-tag v0 baseline (admin-route hardening, dev-mode/secure-admin, exposed config files, known-vulnerable module versions). No new checks land in v1; the focus is the persistence + admin loop.
- **No outbound network calls.** Module remains read-only and offline by default per the v3+ opt-in design in the tracking epic.

### Install

```
composer require ironcartlabs/magento-scan:^1.0@alpha
bin/magento module:enable IronCart_Scan
bin/magento setup:upgrade
```

[Unreleased]: https://github.com/IronCartLabs/IronCartM2/compare/v1.6.0...HEAD
[1.6.0]: https://github.com/IronCartLabs/IronCartM2/compare/v1.5.1...v1.6.0
[1.5.1]: https://github.com/IronCartLabs/IronCartM2/compare/v1.5.0...v1.5.1
[1.5.0]: https://github.com/IronCartLabs/IronCartM2/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/IronCartLabs/IronCartM2/releases/tag/v1.4.0
[1.3.0]: https://github.com/IronCartLabs/IronCartM2/releases/tag/v1.3.0
[1.2.0]: https://github.com/IronCartLabs/IronCartM2/releases/tag/v1.2.0
[1.1.0]: https://github.com/IronCartLabs/IronCartM2/releases/tag/v1.1.0
[1.0.0-alpha.1]: https://github.com/IronCartLabs/IronCartM2/releases/tag/v1.0.0-alpha.1
