# IronCartM2

Magento 2 security scanner module by [Ironcart](https://ironcart.dev) — read-only security posture checks for Adobe Commerce and Magento Open Source, installable via Composer.

> **Status:** pre-release (v0 scaffolding). Not yet usable. Track progress in [issues](https://github.com/IronCartLabs/IronCartM2/issues).

## What it does

Runs a battery of whitebox checks against a live Magento 2 install — checks that no external scanner can perform — and emits a structured JSON report with severities and remediation links.

Example checks (v0):

- Magento version + outstanding security patches
- `MAGE_MODE` posture (developer mode in production = critical)
- Admin URL frontname (default `/admin` = high)
- Admin user inventory: count, last-login age, 2FA coverage
- `app/etc/env.php` permissions and crypt key presence
- Composer advisories against `composer.lock`
- Secure cookie and HTTPS configuration
- Indexer and cron health

### Check IDs

The full v0 check inventory, in stable ID order:

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
| IC-051 | critical | CodeSmell   | `unserialize($_REQUEST/$_GET/$_POST/$_COOKIE)` — RCE vector |
| IC-052 | high     | CodeSmell   | Dynamic `include`/`require` (variable path) — LFI / RFI vector |
| IC-053 | high     | CodeSmell   | Shell execution from PHP (`shell_exec`, `exec`, backticks, …) |
| IC-054 | critical | CodeSmell   | `preg_replace` with `/e` modifier — RCE vector |
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

The v2 **CodeSmell** pack scans `<magento_root>/app/code/**/*.php` only. Composer-managed code under `vendor/` is covered by IC-001/IC-002; core code is covered by a separate file-integrity check.

Remediation links follow the pattern `https://ironcart.dev/docs/checks/<ID>`.

### Network access posture

Every check is **read-only by default**. The module's outbound surface is intentionally small and entirely opt-in:

1. **IC-080..IC-085 CSP posture pack** — issues **one HEAD request to the merchant's own storefront base URL** per scan. Gated by `LoopbackHostGuard` (loopback `localhost` / `127.0.0.1` / `*.localhost` / `::1`, RFC1918 / RFC3927 / RFC4193 private addresses, or exactly the hostname Magento has configured as its base URL — anything else is rejected before any socket is opened). UA `IronCart-Scan/<module-version> (security-posture-check)`, 5s timeout, zero redirects. No outbound calls leave the merchant's infrastructure.
2. **IC-060 CVE cross-reference** — **opt-in, default OFF.** When the operator enables `ironcart_scan/cve/enabled` in Stores → Configuration → Ironcart → Scan, the check POSTs the installed Composer package list (name + version only — no PII, no domain, no admin username, no IP) to `https://ironcart.dev/api/cve` for OSV.dev cross-referencing. The hardened cURL client asserts the URL host equals `ironcart.dev` *before* opening a socket; it follows zero redirects, constrains protocols to HTTP / HTTPS, sends no cookies, applies a 10s connect / 30s total timeout, and sends UA `IronCart-Scan/<module-version> (cve-cross-reference)`. Transport failure emits one `IC-061` LOW finding and continues the scan. Payloads with > 500 packages are batched into 200-package chunks.
3. **`bin/magento ironcart:scan --upload`** (v3, optional) — one HTTPS POST to `https://ironcart.dev/api/scan/ingest` after a scan, gated by `ironcart_scan/upload/enabled` (default `0`). Host-pinned to `ironcart.dev`, full TLS verification, `FOLLOWLOCATION=0`, HTTPS-only protocol set. Payload contains findings, composer package list, Magento version + edition, and the store base URL — **never** the admin email or any customer / order PII. See [docs/UPLOAD.md](docs/UPLOAD.md).
4. **Continuous monitoring cron** (v4, optional) — Magento cron job `ironcart_scan_upload_cron` runs `bin/magento ironcart:scan --upload` on the operator-configured schedule (default daily at 03:00 store time). Gated by `ironcart_scan/cron/enabled` (default `0`) AND requires the v3 `--upload` flow above to be enabled. **Outbound only** — the merchant store never accepts inbound connections from ironcart.dev. The merchant controls when scans run by editing the schedule in admin. See [Continuous monitoring](#continuous-monitoring-optional) below.

Later stages add an Admin UI, continuous scanning, and a Marketplace listing. See the [v0 epic](https://github.com/IronCartLabs/IronCartM2/issues) for the full roadmap.

## Install

```bash
composer require ironcartlabs/magento-scan
bin/magento module:enable IronCart_Scan
bin/magento setup:upgrade
```

## Run

```bash
bin/magento ironcart:scan --format=json --output=./ironcart-scan.json
```

## Upload to ironcart.dev (optional)

v3 adds an optional `--upload` flag that POSTs the scan results to
[ironcart.dev](https://ironcart.dev) for hosted viewing, alerting, and
team sharing. **Off by default.** Enable in admin:

1. Sign up at [ironcart.dev/scanner](https://ironcart.dev/scanner) (or claim an existing anonymous scan) and copy your token.
2. In Magento admin: **Stores → Configuration → Ironcart → Scan → Scan Upload**.
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

Full wire contract, payload shape, and operator-troubleshooting matrix:
[docs/UPLOAD.md](docs/UPLOAD.md).

## Continuous monitoring (optional)

v4 adds a Magento cron job that runs `bin/magento ironcart:scan --upload`
on a schedule you control, so [ironcart.dev](https://ironcart.dev) always
has a fresh view of your store's posture without you remembering to run
the CLI by hand.

> **Outbound only.** Your store does **not** accept any inbound
> connections from ironcart.dev. The cron is a pull-from-store-and-push-
> outbound loop — the merchant store decides when to run, and ironcart.dev
> is purely a receiver. This preserves the read-only, opt-in-network
> posture of the module.

**Off by default.** Enable in admin:

1. Configure the v3 upload flow first (see above) — paste your token,
   set **Enable scan upload to ironcart.dev** = Yes. The cron reuses the
   same token; no separate credential surface.
2. In Magento admin: **Stores → Configuration → Ironcart → Scan →
   Continuous Monitoring**.
3. Set **Enable scheduled scan + upload** = Yes.
4. Optionally edit **Schedule (crontab expression)** — defaults to
   `0 3 * * *` (daily at 03:00 store-server time). Standard crontab
   syntax, re-read on every cron tick (no `cron:install` reboot needed).
5. Save and flush config (`bin/magento cache:flush config`).

Manual trigger for testing:

```bash
bin/magento cron:run --group=ironcart_scan
```

Each run logs a single success or failure line to
`var/log/ironcart_scan.log`:

```
[2026-05-17T03:00:01+00:00] ironcart_scan_cron.INFO: IronCart_Scan: cron upload run starting (continuous monitoring).
[2026-05-17T03:00:04+00:00] ironcart_scan_cron.INFO: IronCart_Scan: cron upload succeeded {"view_url":"https://ironcart.dev/scan/abc123"}
```

If your free-tier quota on ironcart.dev is exhausted, the cron logs an
"upgrade required" line with the `upgrade_url` returned by the server,
exits non-zero, and the `cron_schedule` row goes red so your standard
cron-monitoring tooling picks it up:

```
[2026-05-17T03:00:04+00:00] ironcart_scan_cron.WARNING: IronCart_Scan: cron upload blocked — upgrade required {"upgrade_url":"https://ironcart.dev/pricing?from=cron-402","category":"quota_exceeded"}
```

Full documentation:
[ironcart.dev/docs/scanner/continuous-monitoring](https://ironcart.dev/docs/scanner/continuous-monitoring).

## Running scans asynchronously

The admin **Run Scan Now** button (Stores → Ironcart → Scans → Run Scan
Now) and the v4 continuous-monitoring cron both enqueue scans via
Magento's DB message queue rather than running them inline. The queued
row is created up-front so the admin grid shows it immediately, then a
**queue consumer** picks it up and runs the actual checks.

The consumer is named `ironcartScanRunConsumer` (declared in
`etc/queue_consumer.xml`). Magento ships two supported ways to keep
queue consumers draining — pick the one that fits your hosting:

### Option A: foreground / supervisor worker

```bash
bin/magento queue:consumers:start ironcartScanRunConsumer
```

This runs the consumer as a long-lived process that picks up messages
as soon as they're published. In production, wrap it in a supervisor
(systemd unit, supervisord, pm2, etc.) so it restarts on failure.
Example systemd unit:

```ini
[Service]
ExecStart=/var/www/magento/bin/magento queue:consumers:start ironcartScanRunConsumer
Restart=always
User=www-data
WorkingDirectory=/var/www/magento
```

### Option B: cron-driven (no extra processes)

Magento's core `consumers_runner` cron job will start every declared
consumer on every cron tick (default every minute), run it for a
bounded number of messages, then exit. Enable it by ensuring the
`cron_consumers_runner` configuration in `app/etc/env.php` is either
absent or has `consumers_only` unset / containing
`ironcartScanRunConsumer`:

```php
// app/etc/env.php
'cron_consumers_runner' => [
    'cron_run' => true,
    'max_messages' => 1000,
    // Leave 'consumers' unset to run all declared consumers, or include
    // 'ironcartScanRunConsumer' explicitly if you keep an allowlist.
    'consumers' => [
        'ironcartScanRunConsumer',
        // … other consumers you want to run
    ],
],
```

Confirm Magento's cron itself is running (`bin/magento cron:install` if
not), then a fresh **Run Scan Now** click should flip from `QUEUED` to
`SUCCEEDED` within one cron tick.

### Detection: the stuck-QUEUED admin notice

On installs where neither option above is in place — i.e. the consumer
is never being driven — every **Run Scan Now** click leaves a row
permanently at status `QUEUED` with an empty `Finished` column and
all-zero severity totals. To stop that bug from being silent, the
module fires an admin notice (severity MAJOR, visible in the admin
notice bell) whenever it sees any `ironcart_scan_run` row whose status
is `queued` and whose `started_at` is older than 60 seconds. The
threshold is operator-tunable via
`ironcart_scan/runtime/consumer_alert_threshold_seconds` (lower it to
fire faster on a sluggish consumer; raise it if you have a chronically
slow cron tick).

The notice clears automatically the next time the queued rows drain to
a terminal status.

## Compatibility

- Magento 2.4.4, 2.4.5, 2.4.6, 2.4.7
- PHP 8.1, 8.2, 8.3
- Adobe Commerce and Magento Open Source

## Local development

Run `make sandbox` for a one-command Magento 2 install with this module
symlinked in (wraps [markshust/docker-magento](https://github.com/markshust/docker-magento)).
See [docs/sandbox.md](docs/sandbox.md) for prerequisites, Adobe auth keys, the
M2/PHP matrix, and known papercuts.

## Security

This module is read-only. Through v1 it makes zero network calls of any kind. v2 adds two outbound surfaces, both detailed in [Network access posture](#network-access-posture) above: the IC-080..IC-085 CSP HEAD probe (gated by a loopback / RFC1918 / configured-base-URL allow-list) and the opt-in IC-060 CVE cross-reference POST (gated by an `ironcart.dev`-host allowlist, default OFF). v3 adds the optional `--upload` flag for hosted reporting at ironcart.dev (off by default; see [docs/UPLOAD.md](docs/UPLOAD.md)). v4 adds an optional Magento cron job that drives the `--upload` flow on the operator's schedule (off by default; see [Continuous monitoring](#continuous-monitoring-optional)) — outbound only, the merchant store accepts no inbound connections from ironcart.dev. See [SECURITY.md](SECURITY.md) for the vulnerability disclosure policy.

## License

MIT — see [LICENSE](LICENSE).
