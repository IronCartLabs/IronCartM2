# Changelog

All notable changes to `ironcartlabs/magento-scan` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`bin/magento ironcart:scan --upload`** (v3, [#57](https://github.com/IronCartLabs/IronCartM2/issues/57)). Optional, opt-in HTTPS POST of the scan results to `https://ironcart.dev/api/scan/ingest`. Off by default. Hardened cURL client with host-pinning to `ironcart.dev`, `FOLLOWLOCATION=0`, HTTPS-only protocol set, and full TLS verification. Module-side payload size guards (500 findings / 1000 composer packages). Admin email and operator email are forbidden from the payload tree at every nesting depth; the module refuses to upload if either appears. Admin config exposed under **Stores → Configuration → Ironcart → Scan → Scan Upload**. See [docs/UPLOAD.md](docs/UPLOAD.md).

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

[1.0.0-alpha.1]: https://github.com/IronCartLabs/IronCartM2/releases/tag/v1.0.0-alpha.1
