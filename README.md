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

Later stages add an Admin UI, expanded check library (code smell, CVE cross-reference, file integrity), opt-in hosted reporting, continuous scanning, and a Marketplace listing. See the [v0 epic](https://github.com/IronCartLabs/IronCartM2/issues) for the full roadmap.

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

This module is read-only and performs **no outbound network calls** in v0–v2. Opt-in hosted reporting arrives in v3. See [SECURITY.md](SECURITY.md) for the vulnerability disclosure policy.

## License

MIT — see [LICENSE](LICENSE).
