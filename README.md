# IronCartM2

Magento 2 security scanner module by [Ironcart](https://ironcart.dev). Read-only security posture checks for Adobe Commerce and Magento Open Source, installable via Composer.

```bash
composer require ironcartlabs/magento-scan
```

## What it does

Runs a battery of whitebox checks against a live Magento 2 install (checks that no external scanner can perform) and emits a structured JSON report with severities and remediation links.

Representative checks:

- Magento version and outstanding security patches
- `MAGE_MODE` posture (developer mode in production = critical)
- Admin URL frontname (default `/admin` = high)
- Admin user inventory: count, last-login age, 2FA coverage
- `app/etc/env.php` permissions and crypt key presence
- Composer advisories against `composer.lock`
- Secure cookie and HTTPS configuration
- Indexer and cron health
- Core file integrity (SHA-256 against bundled reference manifests)
- Code-smell pattern scan over `app/code/` (`eval`, dynamic `include`, `preg_replace /e`, etc.)
- Content-Security-Policy posture probe against the storefront base URL
- Webhook subscription hygiene (plaintext HTTP, missing signing secret, private-network destinations)

All 43+ checks are included free under MIT.

### Check IDs

The check inventory, in stable ID order:

| ID | Severity (default) | Pack | Summary |
|----|--------------------|------|---------|
| IC-001 | high     | PatchLevel  | Magento version vs latest security patch |
| IC-002 | high     | PatchLevel  | Composer advisories against `composer.lock` |
| IC-010 | high     | Admin       | Admin URL frontname is default `/admin` |
| IC-011 | medium   | Admin       | Stale active admin accounts (no login > 90d) |
| IC-012 | high     | Admin       | 2FA coverage across admin users |
| IC-013 | medium   | Admin       | Weak-password indicators on admin accounts |
| IC-020 | critical | Runtime     | `MAGE_MODE` is `developer` in production |
| IC-021 | high     | Runtime     | Cookies not flagged secure / httpOnly |
| IC-022 | high     | Runtime     | HTTPS not enforced on storefront/admin |
| IC-023 | medium   | Runtime     | CSP mode (report-only vs. enforced) |
| IC-024 | medium   | Runtime     | Profiler enabled in production |
| IC-030 | high     | Filesystem  | `app/etc/env.php` is world-readable |
| IC-031 | medium   | Filesystem  | `app/etc/env.php` ownership mismatch |
| IC-032 | high     | Filesystem  | Crypt key missing or default-shaped |
| IC-033 | medium   | Filesystem  | Unexpectedly writable directories |
| IC-034 | low      | Filesystem  | Stray dev-tooling files in document root |
| IC-040 | medium   | Operational | Indexer is in invalid / reindex-required state |
| IC-041 | medium   | Operational | Cron last-run age exceeds threshold |
| IC-042 | medium   | Operational | Cron error rate over the recent window |
| IC-043 | medium   | Operational | Message-queue backlog over depth threshold |
| IC-050 | critical | CodeSmell   | `eval()` invocation in `app/code/**` |
| IC-051 | critical | CodeSmell   | `unserialize($_REQUEST/$_GET/$_POST/$_COOKIE)`, RCE vector |
| IC-052 | high     | CodeSmell   | Dynamic `include`/`require` (variable path), LFI / RFI vector |
| IC-053 | high     | CodeSmell   | Shell execution from PHP (`shell_exec`, `exec`, backticks, ...) |
| IC-054 | critical | CodeSmell   | `preg_replace` with `/e` modifier, RCE vector |
| IC-060 | varies   | Cve         | Composer package CVE cross-reference via `ironcart.dev/api/cve` proxy (opt-in, default OFF; severity from advisory CVSS v3 score) |
| IC-061 | low      | Cve         | OSV cross-reference unavailable (IC-060 transport / parse failure fallback) |
| [IC-070](https://ironcart.dev/docs/checks/IC-070) | high     | FileIntegrity | Core file SHA-256 differs from bundled reference manifest |
| [IC-071](https://ironcart.dev/docs/checks/IC-071) | low      | FileIntegrity | Core file integrity manifest not available for this Magento version |
| [IC-072](https://ironcart.dev/docs/checks/IC-072) | high     | FileIntegrity | `composer.lock` package `dist.shasum` differs from reference manifest |
| [IC-073](https://ironcart.dev/docs/checks/IC-073) | low      | FileIntegrity | Composer integrity manifest not available for this Magento version |
| IC-080 | high     | Runtime/Csp | Storefront response has no `Content-Security-Policy` header |
| IC-081 | medium   | Runtime/Csp | CSP has no `report-uri` / `report-to` directive |
| IC-082 | high     | Runtime/Csp | `script-src` (or `default-src` fallback) allows `'unsafe-inline'` / `'unsafe-eval'` |
| IC-083 | medium   | Runtime/Csp | `frame-ancestors` missing or set to `*` |
| IC-084 | high     | Runtime/Csp | Storefront CSP is `report-only` while `MAGE_MODE=production` |
| IC-085 | low      | Runtime/Csp | Storefront base URL appears unconfigured (default `example.com`) |
| IC-090 | high     | Webhooks    | Webhook destination over plaintext HTTP |
| IC-091 | high     | Webhooks    | Webhook signature secret missing |
| IC-092 | medium   | Webhooks    | Webhook retry policy unsafe (too many / too short) |
| IC-093 | medium   | Webhooks    | Webhook destination resolves to a private network |
| IC-910 | medium   | Hyva        | Hyvä Tailwind / postcss config file reachable under `pub/static/` |
| IC-911 | medium / low | Hyva    | Hyvä Checkout CSP whitelist contains hashes not present in the installed checkout version (medium); manifest unavailable for installed version (low) |
| IC-912 | high / medium | Hyva   | `hyva-themes/*` composer package installed below the bundled min-version floor (high when the floor is security-tagged, medium otherwise) |
| IC-913 | medium   | Hyva        | Hyvä theme template references Alpine.js from a public JS CDN (jsdelivr / unpkg / cdnjs / esm.sh / jspm / skypack) instead of a vendored asset |
| IC-921 | medium   | PwaStudio   | GraphQL introspection enabled (`graphql/validation/disable_introspection = 0`) while `MAGE_MODE=production` |
| IC-922 | medium   | PwaStudio   | GraphQL `maximum_query_depth` / `maximum_query_complexity` missing or above safe ceilings (depth > 20, complexity > 300) |
| IC-923 | high     | PwaStudio   | GraphQL `web/graphql/cors_allowed_origins` contains a wildcard (`*`, `null`, or `*.example.com`) |
| IC-200 | high     | Integrity   | `app/etc/env.php` file mode is not `0640` or stricter |
| IC-201 | high     | Integrity   | `app/etc/env.php` owner is `root` or a known webserver user |
| IC-202 | high     | Integrity   | `app/etc/env.php` is a symlink |
| IC-203 | high     | Integrity   | `crypt.key` matches a documented default value |
| IC-204 | high     | Integrity   | A `db.connection.*` entry has an empty password |
| IC-205 | high     | Integrity   | `session.save = 'files'` with no explicit `save_path` |

The **Hyva** pack (IC-910..IC-913) only emits findings when the storefront is detected as Hyvä — either the `Hyva_Theme` module is registered with Magento, or `hyva-themes/*` packages are present in `composer.lock`. Non-Hyvä stores see zero findings from this pack. Detection is read-only and runs only when Magento itself is detected.

The **PwaStudio** pack (IC-921..IC-923) only emits findings when PWA Studio is detected — either a `magento/pwa` / `magento/module-pwa` composer package is installed, or the Magento-root `package.json` references `@magento/pwa-studio` / `@magento/venia-ui` / `@magento/peregrine` / `@magento/venia-concept`, or a `pwa-studio.config.json` / `venia.config.json` / `packages/venia-concept/` marker exists at the Magento root. Detection is read-only.

The **CodeSmell** pack scans `<magento_root>/app/code/**/*.php` only. Composer-managed code under `vendor/` is covered by IC-001/IC-002; core code is covered by the file-integrity pack (IC-070..IC-073).

Remediation links follow the pattern `https://ironcart.dev/docs/checks/<ID>`.

### Network access posture

Every check is **read-only by default**. The module's outbound surface is intentionally small and entirely opt-in:

1. **IC-080..IC-085 CSP posture pack**: issues **one HEAD request to the merchant's own storefront base URL** per scan. Gated by `LoopbackHostGuard` (loopback `localhost` / `127.0.0.1` / `*.localhost` / `::1`, RFC1918 / RFC3927 / RFC4193 private addresses, or exactly the hostname Magento has configured as its base URL; anything else is rejected before any socket is opened). UA `IronCart-Scan/<module-version> (security-posture-check)`, 5s timeout, zero redirects. No outbound calls leave the merchant's infrastructure.
2. **IC-060 CVE cross-reference**: **opt-in, default OFF.** When the operator enables `ironcart_scan/cve/enabled` in Stores > Configuration > Ironcart > Scan, the check POSTs the installed Composer package list (name + version only; no PII, no domain, no admin username, no IP) to `https://ironcart.dev/api/cve` for OSV.dev cross-referencing. The hardened cURL client asserts the URL host equals `ironcart.dev` *before* opening a socket; it follows zero redirects, constrains protocols to HTTP / HTTPS, sends no cookies, applies a 10s connect / 30s total timeout, and sends UA `IronCart-Scan/<module-version> (cve-cross-reference)`. Transport failure emits one `IC-061` LOW finding and continues the scan. Payloads with > 500 packages are batched into 200-package chunks.
3. **`bin/magento ironcart:scan --upload`** (optional): one HTTPS POST to `https://ironcart.dev/api/scan/ingest` after a scan, gated by `ironcart_scan/upload/enabled` (default `0`). Host-pinned to `ironcart.dev`, full TLS verification, `FOLLOWLOCATION=0`, HTTPS-only protocol set. Payload contains findings, composer package list, Magento version + edition, and the store base URL; **never** the admin email or any customer / order PII. See [docs/UPLOAD.md](docs/UPLOAD.md).
4. **Continuous monitoring cron** (optional): Magento cron job `ironcart_scan_upload_cron` runs `bin/magento ironcart:scan --upload` on the operator-configured schedule (default daily at 03:00 store time). Gated by `ironcart_scan/cron/enabled` (default `0`) AND requires the `--upload` flow above to be enabled. **Outbound only**: the merchant store never accepts inbound connections from ironcart.dev. The merchant controls when scans run by editing the schedule in admin. See [Continuous monitoring](#continuous-monitoring-optional) below.

## Install

```bash
composer require ironcartlabs/magento-scan
bin/magento module:enable IronCart_Scan
bin/magento setup:upgrade
```

Requires Magento 2.4.4 or later and PHP 8.1 / 8.2 / 8.3. Works on Adobe Commerce and Magento Open Source.

## Run

```bash
bin/magento ironcart:scan --format=json --output=./ironcart-scan.json
```

## Upload to ironcart.dev (optional)

The `--upload` flag POSTs the scan results to [ironcart.dev](https://ironcart.dev) for a hosted, shareable report. **Off by default.** Enable in admin:

1. Sign up at [ironcart.dev/scanner](https://ironcart.dev/scanner) (or claim an existing anonymous scan) and copy your token.
2. In Magento admin: **Stores > Configuration > Ironcart > Scan > Scan Upload**.
3. Set **Enable scan upload to ironcart.dev** = Yes.
4. Paste your token into **ironcart.dev upload token**.
5. Save.

Then:

```bash
bin/magento ironcart:scan --upload --format=json
```

The command prints `Scan uploaded: <view_url>` after a successful upload.

**What gets sent:** scan findings, composer package list, Magento version + edition, store base URL.<br/>
**What is NEVER sent:** your Magento admin email, customer / order PII, secrets from `app/etc/env.php`, or any session cookies.

The free tier allows 3 lifetime uploads. For continuous monitoring, multi-channel notifications, and additional server-side external scan checks, pair the module with a Recon subscription on ironcart.dev.

Full wire contract, payload shape, and operator-troubleshooting matrix: [docs/UPLOAD.md](docs/UPLOAD.md).

### Multi-store / agency: env vars + CLI overrides

Agencies running one Composer install per client can skip the admin UI paste flow. The license blob and upload token resolve in this order, highest precedence first:

1. **CLI override** — `bin/magento ironcart:scan --upload --license=<blob> --upload-token=<token>`. One-shot; never persisted to `core_config_data`.
2. **Env var** — `IRONCART_SCAN_LICENSE_BLOB`, `IRONCART_SCAN_UPLOAD_TOKEN`, `IRONCART_SCAN_UPLOAD_ENABLED`. Read at scan time; useful on Magento Cloud, Docker, Kubernetes, CI.
3. **Admin config** — the existing **Stores > Configuration > Ironcart > Scan** paste flow. Per-website / per-store scope wins over default scope via Magento's standard scope resolution.

Verification posture is identical at every layer — the same Ed25519 `LicenseVerifier` runs on the resolved value. See [docs/UPLOAD.md#multi-store-agency-configuration-env-vars--cli-overrides](docs/UPLOAD.md#multi-store-agency-configuration-env-vars--cli-overrides) for the full resolution table and examples.

## Continuous monitoring (optional)

The module ships a Magento cron job that runs `bin/magento ironcart:scan --upload` on a schedule you control, so [ironcart.dev](https://ironcart.dev) always has a fresh view of your store's posture without you remembering to run the CLI by hand.

> **Outbound only.** Your store does **not** accept any inbound connections from ironcart.dev. The cron is a pull-from-store-and-push-outbound loop: the merchant store decides when to run, and ironcart.dev is purely a receiver. This preserves the read-only, opt-in-network posture of the module.

**Off by default.** Enable in admin:

1. Configure the upload flow first (see above): paste your token, set **Enable scan upload to ironcart.dev** = Yes. The cron reuses the same token; no separate credential surface.
2. In Magento admin: **Stores > Configuration > Ironcart > Scan > Continuous Monitoring**.
3. Set **Enable scheduled scan + upload** = Yes.
4. Optionally edit **Schedule (crontab expression)**. Defaults to `0 3 * * *` (daily at 03:00 store-server time). Standard crontab syntax, re-read on every cron tick (no `cron:install` reboot needed).
5. Save and flush config (`bin/magento cache:flush config`).

Manual trigger for testing:

```bash
bin/magento cron:run --group=ironcart_scan
```

Each run logs a single success or failure line to `var/log/ironcart_scan.log`:

```
[2026-05-17T03:00:01+00:00] ironcart_scan_cron.INFO: IronCart_Scan: cron upload run starting (continuous monitoring).
[2026-05-17T03:00:04+00:00] ironcart_scan_cron.INFO: IronCart_Scan: cron upload succeeded {"view_url":"https://ironcart.dev/scan/abc123"}
```

If your free-tier quota on ironcart.dev is exhausted, the cron logs an "upgrade required" line with the `upgrade_url` returned by the server, exits non-zero, and the `cron_schedule` row goes red so your standard cron-monitoring tooling picks it up:

```
[2026-05-17T03:00:04+00:00] ironcart_scan_cron.WARNING: IronCart_Scan: cron upload blocked (upgrade required) {"upgrade_url":"https://ironcart.dev/pricing?from=cron-402","category":"quota_exceeded"}
```

Full documentation: [ironcart.dev/docs/scanner/continuous-monitoring](https://ironcart.dev/docs/scanner/continuous-monitoring).

## Running scans asynchronously

The admin **Run Scan Now** button (Stores > Ironcart > Scans > Run Scan Now) and the continuous-monitoring cron both enqueue scans via Magento's DB message queue rather than running them inline. The queued row is created up-front so the admin grid shows it immediately, then a **queue consumer** picks it up and runs the actual checks.

The consumer is named `ironcartScanRunConsumer` (declared in `etc/queue_consumer.xml`). Magento ships two supported ways to keep queue consumers draining; pick the one that fits your hosting.

### Option A: foreground / supervisor worker

```bash
bin/magento queue:consumers:start ironcartScanRunConsumer
```

This runs the consumer as a long-lived process that picks up messages as soon as they're published. In production, wrap it in a supervisor (systemd unit, supervisord, pm2, etc.) so it restarts on failure. Example systemd unit:

```ini
[Service]
ExecStart=/var/www/magento/bin/magento queue:consumers:start ironcartScanRunConsumer
Restart=always
User=www-data
WorkingDirectory=/var/www/magento
```

### Option B: cron-driven (no extra processes)

Magento's core `consumers_runner` cron job will start every declared consumer on every cron tick (default every minute), run it for a bounded number of messages, then exit. Enable it by ensuring the `cron_consumers_runner` configuration in `app/etc/env.php` is either absent or has `consumers_only` unset / containing `ironcartScanRunConsumer`:

```php
// app/etc/env.php
'cron_consumers_runner' => [
    'cron_run' => true,
    'max_messages' => 1000,
    // Leave 'consumers' unset to run all declared consumers, or include
    // 'ironcartScanRunConsumer' explicitly if you keep an allowlist.
    'consumers' => [
        'ironcartScanRunConsumer',
        // ... other consumers you want to run
    ],
],
```

Confirm Magento's cron itself is running (`bin/magento cron:install` if not), then a fresh **Run Scan Now** click should flip from `QUEUED` to `SUCCEEDED` within one cron tick.

### Detection: the stuck-QUEUED admin notice

On installs where neither option above is in place (the consumer is never being driven), every **Run Scan Now** click leaves a row permanently at status `QUEUED` with an empty `Finished` column and all-zero severity totals. To stop that bug from being silent, the module fires an admin notice (severity MAJOR, visible in the admin notice bell) whenever it sees any `ironcart_scan_run` row whose status is `queued` and whose `started_at` is older than 60 seconds. The threshold is operator-tunable via `ironcart_scan/runtime/consumer_alert_threshold_seconds` (lower it to fire faster on a sluggish consumer; raise it if you have a chronically slow cron tick).

The notice clears automatically the next time the queued rows drain to a terminal status.

## Compatibility

- Magento 2.4.4, 2.4.5, 2.4.6, 2.4.7
- PHP 8.1, 8.2, 8.3
- Adobe Commerce and Magento Open Source

## Translations

Bundled locales:

- `en_US`: source
- `de_DE`, `fr_FR`, `es_ES`, `nl_NL`: **machine-translated stubs**

Set `MAGE_DEFAULT_LOCALE=de_DE` (or change **Stores > Configuration > General > Locale Options**) and the CLI help text plus the admin findings grid render in the active locale. The JSON report (`bin/magento ironcart:scan --format=json`) is locale-independent: finding `title` / `severity` are stable English so downstream consumers can grep them.

Native-speaker refinements are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md#translations).

## Local development

Run `make sandbox` for a one-command Magento 2 install with this module symlinked in (wraps [markshust/docker-magento](https://github.com/markshust/docker-magento)). See [docs/sandbox.md](docs/sandbox.md) for prerequisites, Adobe auth keys, the M2/PHP matrix, and known papercuts.

## Testing

Three layers of automated coverage run in CI on every PR (`.github/workflows/ci.yml`):

- **Unit tests** — `Test/Unit/**` via PHPUnit on PHP 8.1 / 8.2 / 8.3. No Magento source needed; the CI cell strips `magento/framework` from `composer.json` before installing so the Magento-free `Test/Unit/Report/**` slice runs cleanly. Magento-typed test subtrees (`Test/Unit/Check/**`) are validated end-to-end by the integration cells below.
- **Lint** — `magento/magento-coding-standard` ^32 (phpcs) + phpstan level 6 against the pure-PHP report builder slice.
- **Integration sandbox cells** — docker-compose Magento sandbox (MariaDB + OpenSearch + Redis + `markoshust/magento-php` pinned to a sha256 digest) booted by three cells:
  - `integration` — default **Luma** storefront; runs `bin/magento ironcart:scan --format=json` and asserts the v0 report shape (`schema_version`, `findings`, `summary`) plus the IC-072 composer-lock baseline.
  - `integration-hyva` — adds `hyva-themes/magento2-theme-module` and plants an IC-913 CDN-Alpine fixture template under `app/design/frontend/`, then runs `tests/sandbox/hyva-integration.php` to assert IC-910..IC-913 and the CheckRegistry wiring.
  - `integration-pwa` — plants PWA Studio detection fixtures (`package.json` + `pwa-studio.config.json` markers; no npm install) and pre-configures the GraphQL admin knobs IC-921 / IC-922 / IC-923 read, then runs `tests/sandbox/pwa-integration.php` to assert the PWA pack fires end-to-end.

  All three integration cells are gated on the `INTEGRATION_ENABLED` repo variable (Magento composer auth wiring lives in repo secrets — see [#18](https://github.com/IronCartLabs/IronCartM2/issues/18)). Pinned to Magento 2.4.7-p5 / PHP 8.3 on PR runs; the full Magento 2.4.4–2.4.7 × PHP 8.1–8.3 matrix runs on pushes to `main` or PRs labelled `v0`.

## Security

This module is read-only. Its outbound network surface is documented in [Network access posture](#network-access-posture) above and is opt-in by default:

- The IC-080..IC-085 CSP HEAD probe is gated by a loopback / RFC1918 / configured-base-URL allow-list.
- The IC-060 CVE cross-reference POST is gated by an `ironcart.dev` host allowlist (default OFF).
- The `--upload` flag for hosted reporting at ironcart.dev is off by default (see [docs/UPLOAD.md](docs/UPLOAD.md)).
- The Magento cron job that drives the `--upload` flow on the operator's schedule is off by default (see [Continuous monitoring](#continuous-monitoring-optional)). Outbound only: the merchant store accepts no inbound connections from ironcart.dev.

See [SECURITY.md](SECURITY.md) for the vulnerability disclosure policy.

## License

MIT, see [LICENSE](LICENSE).
