# Changelog

All notable changes to `ironcartlabs/magento-scan` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Adobe Marketplace EQP CSP-readiness pass. No behaviour change for
merchants — the admin "Run scan now" button continues to enqueue +
poll exactly as before; the wiring just stops violating strict CSP.

### Changed

- **`Ui/Component/Control/RunScanNowButton`** ([#85](https://github.com/IronCartLabs/IronCartM2/issues/85)). Refactored from an `on_click` inline-JS handler (`require([...], function (run) { run(<json>, <json>); });`) to a declarative `data-mage-init` attribute. The button provider now emits a `data_attribute` array carrying a JSON payload keyed at `IronCart_Scan/js/run-scan-now-init`; Magento's `mage/apply` bootstrap resolves the module on DOM ready and binds a regular `click` listener. The underlying `view/adminhtml/web/js/run-scan-now.js` module and its `runScanNow(runUrl, statusUrl)` public surface are unchanged, so the #77 polling-throttle regression suite (`MAX_INFLIGHT`, `inflightIds`, `tickInProgress`, `postInFlight`) keeps passing without modification.

### Added

- **`view/adminhtml/web/js/run-scan-now-init.js`** ([#85](https://github.com/IronCartLabs/IronCartM2/issues/85)). Thin shim that adapts the `data-mage-init` config object to the existing `IronCart_Scan/js/run-scan-now` module. Pulls `runUrl` / `statusUrl` out of the config, wires an `addEventListener('click', ...)` on the button element, and delegates. No new module surface, no inline JS, no URL building in the browser.
- **`etc/csp_whitelist.xml`** ([#85](https://github.com/IronCartLabs/IronCartM2/issues/85), [EQP audit item 30](docs/marketplace-eqp-audit.md)). Declares the module's outbound `connect-src` host (`ironcart.dev`) so admins running `system/csp/mode_admin = restrict_mode` (enforced CSP) don't lose IC-060 / `--upload` / v4 cron functionality. No `script-src` entries are needed — after this refactor the module emits zero inline JS.

### Notes

- The module-version constants (`etc/module.xml` `setup_version`, `composer.json` `extra.module-version`) are unchanged at `1.2.0`. This refactor will ship in `1.2.1` per semver (patch — no behaviour change, no API change). The version bump and `[1.2.1]` heading land in the release PR, not here.
- Verifies against EQP audit items 29 (inline JS on admin button) and 30 (`etc/csp_whitelist.xml` absent) in [`docs/marketplace-eqp-audit.md`](docs/marketplace-eqp-audit.md). The audit doc is a snapshot — it gets re-walked at the next release-readiness pass, not edited here.

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

[Unreleased]: https://github.com/IronCartLabs/IronCartM2/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/IronCartLabs/IronCartM2/releases/tag/v1.2.0
[1.1.0]: https://github.com/IronCartLabs/IronCartM2/releases/tag/v1.1.0
[1.0.0-alpha.1]: https://github.com/IronCartLabs/IronCartM2/releases/tag/v1.0.0-alpha.1
