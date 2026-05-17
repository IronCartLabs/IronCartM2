# Changelog

All notable changes to `ironcartlabs/magento-scan` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.1.0]: https://github.com/IronCartLabs/IronCartM2/releases/tag/v1.1.0
[1.0.0-alpha.1]: https://github.com/IronCartLabs/IronCartM2/releases/tag/v1.0.0-alpha.1
