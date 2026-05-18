# IronCart Scan — Marketplace edition

This directory holds the **Adobe Commerce Marketplace** packaging for the IronCart Scan module.

## Relationship to the OSS package

The Marketplace package — `ironcartlabs/magento-scan-marketplace` — is a one-for-one mirror of the OSS package `ironcartlabs/magento-scan`. **Both packages install the same module code** (`IronCart_Scan`) at the same Magento install path, register the same component, and behave identically at runtime.

The two packages exist for one reason only: Adobe's EQP (Extension Quality Program) review and the Marketplace listing process require packaging metadata, screenshots, descriptions, and a release cadence that is not appropriate for the OSS Packagist listing. Keeping the Marketplace package separate lets the OSS repo stay focused on open-source contributors and lets Adobe-specific compliance work live in this directory.

| Concern | OSS package | Marketplace package |
|---|---|---|
| Composer name | `ironcartlabs/magento-scan` | `ironcartlabs/magento-scan-marketplace` |
| Distribution channel | Packagist | Adobe Commerce Marketplace |
| Source code | `.` (repo root) | `.` (repo root) — same code |
| Module name registered | `IronCart_Scan` | `IronCart_Scan` |
| Magento install path | `app/code/IronCart/Scan` | `app/code/IronCart/Scan` |
| Default install command | `composer require ironcartlabs/magento-scan` | Marketplace dashboard or `composer require ironcartlabs/magento-scan-marketplace` |
| Release cadence | Tagged from `main` on each version bump | Same — built from the same git tag |
| Version source of truth | `etc/module.xml` `setup_version` | Derived from `etc/module.xml` at build time (no hand edits) |

**Do not install both packages on the same Magento install.** Composer will refuse the second install because both register `IronCart_Scan` and own the same `app/code` path.

## Why not just list the OSS package on Marketplace?

That was option A in the v5 scope decision ([IronCartWeb#1071](https://github.com/IronCartLabs/IronCartWeb/issues/1071)). The team chose option B — a Marketplace-specific package — to isolate Adobe EQP compliance work (listing copy, screenshot dimensions, support-SLA promises, the eventual paid-tier integration) from the OSS repo. The Marketplace package's `composer.json` is allowed to carry Adobe-specific metadata fields that would feel out of place on the OSS Packagist listing.

## OSS users — you almost certainly want the OSS package

If you found this directory while browsing the source repo:

- **Just want to scan your store?** Install the OSS package from Packagist — it is the same code and is free under MIT:

  ```bash
  composer require ironcartlabs/magento-scan
  bin/magento module:enable IronCart_Scan
  bin/magento setup:upgrade
  ```

- **Run Adobe Commerce and prefer to install from the Marketplace dashboard?** Install via the Marketplace once the listing is live. The Marketplace package will be the right answer for merchants who already manage extensions through the Adobe dashboard.

OSS Packagist: <https://packagist.org/packages/ironcartlabs/magento-scan>

## Build mechanics

The Marketplace package contents are produced by `make build-marketplace` (wrapper around `php bin/build-marketplace.php`). That script:

1. Reads the canonical module version from `etc/module.xml` `setup_version`.
2. Verifies `package-marketplace/composer.json` `extra.module-version` matches the OSS version. **The build fails on skew** — the Marketplace package can never lag the OSS module in CI.
3. Stages the OSS module source (`Check/`, `Console/`, `Controller/`, `Cron/`, `Model/`, `Report/`, `Ui/`, `data/`, `etc/`, `view/`, `registration.php`, `README.md`, `LICENSE`, `SECURITY.md`) into `package-marketplace/build/staging/`.
4. Drops in the Marketplace-shaped `composer.json` so the staged tree is a complete, installable Composer package.
5. Runs `composer validate --strict --no-check-publish` against the staged `composer.json`.
6. Produces `package-marketplace/build/ironcartlabs-magento-scan-marketplace-<version>.tar.gz` ready for the Marketplace dashboard.

The OSS package continues to be built and tagged the same way it always has — `git tag vX.Y.Z` against the OSS module root. The Marketplace tarball is built from the *same* tag by the `release-marketplace.yml` workflow.

## CI

`.github/workflows/release-marketplace.yml` runs on every tag matching `v*.*.*`:

1. Asserts `etc/module.xml` `setup_version`, root `composer.json` `extra.module-version`, and `package-marketplace/composer.json` `extra.module-version` all agree with the tag.
2. Builds the Marketplace tarball via `make build-marketplace`.
3. Builds the OSS Packagist artifact via `composer archive` against the root `composer.json` (Packagist itself still handles the canonical OSS release — this step exists so any packaging regression is caught here, not on Packagist).
4. Uploads both artifacts to the GitHub Release.

## Submitting to Adobe Marketplace

Out of scope for this skeleton. The submission flow lands in a follow-up issue once the EQP audit (filed separately as the v5 Q4 sub-issue) has been remediated. This README will then grow a "Submission" section with the dashboard URL, the credentials wiring, and the screenshot requirements.

## See also

- v5 scope decision: [IronCartWeb#1071](https://github.com/IronCartLabs/IronCartWeb/issues/1071)
- Tracking epic: [IronCartWeb#884](https://github.com/IronCartLabs/IronCartWeb/issues/884)
- OSS module top-level [README](../README.md)
