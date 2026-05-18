# Changelog

All notable changes to `ironcartlabs/magento-scan` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.4.0] - Unreleased

The v6 module wave. Adds the Hyvä-specific check pack on top of the
1.3.0 baseline. Strictly additive — no removed or renamed CLI / class /
config surface. Non-Hyvä stores see byte-identical scan output to v1.3.0
because every IC-9xx check short-circuits to zero findings on the
detector-says-no path.

### Added — Hyvä check pack

- **`Check/Hyva/HyvaDetector`** ([#125](https://github.com/IronCartLabs/IronCartM2/issues/125)). Shared, DI-singleton detector composing `ModuleListInterface` (looks for the `Hyva_Theme` module) and the existing `ComposerLockReader` (looks for `hyva-themes/*` packages in `composer.lock`). Either signal flips the storefront into Hyvä mode for the IC-9xx pack; the detection record is memoised for the lifetime of the scan run so the three Hyvä-aware checks pay the lookup cost once.
- **IC-910 — Tailwind / postcss config exposed under `pub/static`** ([#125](https://github.com/IronCartLabs/IronCartM2/issues/125)). Walks `<magento_root>/pub/static/frontend/<vendor>/<theme>/` two levels deep looking for `tailwind.config.js`, `tailwind.source.css`, and `postcss.config.js` (both at the theme root and inside the `tailwind/` subdir Hyvä's default theme uses). Severity MEDIUM; remediation at `https://ironcart.dev/docs/checks/IC-910`. Read-only filesystem walk, bounded so a wrecked deploy with hundreds of stale theme directories does not blow the scan timeout.
- **IC-911 — Hyvä Checkout CSP whitelist hash drift** ([#125](https://github.com/IronCartLabs/IronCartM2/issues/125)). Parses the merchant's `app/etc/csp_whitelist.xml` for every `sha256` hash under `<policy id="script-src">` and compares against the bundled `etc/manifests/hyva-checkout/<version>.json` for the installed `hyva-themes/magento2-hyva-checkout` version. Hashes whitelisted but not in the manifest surface as MEDIUM findings. When the installed checkout version is newer than every bundled manifest, IC-911 emits a single LOW informational finding pointing at the manifest-refresh path (`bin/refresh-osv-snapshot.php` cadence). No network call — the manifest ships in-repo. Manifest seed at `etc/manifests/hyva-checkout/1.1.16.json` (placeholder hashes; first real Hyvä Checkout release the manifest covers will replace them).
- **IC-912 — Hyvä module version drift** ([#125](https://github.com/IronCartLabs/IronCartM2/issues/125)). Cross-references every installed `hyva-themes/*` composer package against the bundled `etc/manifests/hyva-modules/min-versions.json` floor manifest. Packages below the floor emit one finding each; severity is HIGH when the floor row is tagged `"security": true` (set because of a published advisory) and MEDIUM otherwise. Packages with no manifest row are silently skipped — IC-002 / IC-060 already provide CVE-driven coverage for the long tail. No network call; refresh path is the same `bin/refresh-osv-snapshot.php` flow as IC-002.

### Changed

- **`etc/di.xml`** appends three new entries to the `CheckRegistry` `checks` argument (`IC-910`, `IC-911`, `IC-912`) and declares `HyvaDetector` as `shared="true"`. Existing entries are unchanged — the v6 pack is strictly additive.

### Notes

- Strictly additive — Free OSS check pack stays open-source. The paywall axis (delivery + enrichment via the Recon subscription) is unchanged; IC-910..IC-912 ship under the MIT module like every other check.
- No outbound network calls. Both manifests (Hyvä Checkout CSP hash + Hyvä module min-version) ship in-repo and are refreshed on the OSV-snapshot cadence.
- Compat matrix unchanged: Magento 2.4.4–2.4.7 × PHP 8.1–8.3 stays green.
- Remediation guide stubs (`docs/checks/IC-910`, `docs/checks/IC-911`, `docs/checks/IC-912`) land in IronCartWeb as a follow-up `agent:content` ticket once the check IDs are locked.
- Tracking epic: [IronCartLabs/IronCartWeb#884](https://github.com/IronCartLabs/IronCartWeb/issues/884) ("v6 — Hyvä/PWA Studio specific checks").

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

[Unreleased]: https://github.com/IronCartLabs/IronCartM2/compare/v1.3.0...HEAD
[1.3.0]: https://github.com/IronCartLabs/IronCartM2/releases/tag/v1.3.0
[1.2.0]: https://github.com/IronCartLabs/IronCartM2/releases/tag/v1.2.0
[1.1.0]: https://github.com/IronCartLabs/IronCartM2/releases/tag/v1.1.0
[1.0.0-alpha.1]: https://github.com/IronCartLabs/IronCartM2/releases/tag/v1.0.0-alpha.1
