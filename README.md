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

Every check is **read-only by default**. Two outbound surfaces exist:

1. **IC-080..IC-085 CSP posture pack** — issues **one HEAD request to the merchant's own storefront base URL** per scan. Gated by `LoopbackHostGuard` (loopback `localhost` / `127.0.0.1` / `*.localhost` / `::1`, RFC1918 / RFC3927 / RFC4193 private addresses, or exactly the hostname Magento has configured as its base URL — anything else is rejected before any socket is opened). UA `IronCart-Scan/<module-version> (security-posture-check)`, 5s timeout, zero redirects. No outbound calls leave the merchant's infrastructure.
2. **IC-060 CVE cross-reference** — **opt-in, default OFF.** When the operator enables `ironcart_scan/cve/enabled` in Stores → Configuration → Ironcart → Scan, the check POSTs the installed Composer package list (name + version only — no PII, no domain, no admin username, no IP) to `https://ironcart.dev/api/cve` for OSV.dev cross-referencing. The hardened cURL client asserts the URL host equals `ironcart.dev` *before* opening a socket; it follows zero redirects, constrains protocols to HTTP / HTTPS, sends no cookies, applies a 10s connect / 30s total timeout, and sends UA `IronCart-Scan/<module-version> (cve-cross-reference)`. Transport failure emits one `IC-061` LOW finding and continues the scan. Payloads with > 500 packages are batched into 200-package chunks.

Later stages add an Admin UI, opt-in hosted reporting, continuous scanning, and a Marketplace listing. See the [v0 epic](https://github.com/IronCartLabs/IronCartM2/issues) for the full roadmap.

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

This module is read-only. Through v1 it makes zero network calls of any kind. v2 adds two outbound surfaces, both detailed in [Network access posture](#network-access-posture) above: the IC-080..IC-085 CSP HEAD probe (gated by a loopback / RFC1918 / configured-base-URL allow-list) and the opt-in IC-060 CVE cross-reference POST (gated by an `ironcart.dev`-host allowlist, default OFF). Opt-in hosted reporting arrives in v3. See [SECURITY.md](SECURITY.md) for the vulnerability disclosure policy.

## License

MIT — see [LICENSE](LICENSE).
