# File-integrity manifests (IC-070)

[`Check/FileIntegrity/CoreFileIntegrityCheck`](../Check/FileIntegrity/CoreFileIntegrityCheck.php)
is the v2 file-integrity check (IC-070). It diffs every core file's
live SHA-256 against an expected-hash manifest. A mismatch (or a
manifest entry that is missing from disk) raises a HIGH-severity
finding; more than 200 alterations escalate to a single CRITICAL
mass-tampering finding.

This document is a how-the-manifests-are-made companion to the runtime
check. For why the manifest format is ours and not Adobe's, see issue
[#47](https://github.com/IronCartLabs/IronCartM2/issues/47).

## Why we ship our own manifest

Adobe does not publish a public file-hash manifest for Magento core.
The closest things — `sri-hashes.json`, `composer.lock` — only cover
narrow slices (static assets, composer packages). To catch the long
tail of webshells in `pub/` and post-install tampering, we need a
file-level oracle and we build it deterministically from the public
[`magento/magento2`](https://github.com/magento/magento2) source tree.

This means the manifest covers files **shipped in the magento2
monorepo** (the `app/`, `bin/`, `dev/`, `lib/`, `pub/`, `setup/`, plus
root configs). It does **not** cover the `vendor/` tree assembled by
`composer install`. That's a meaningful scope reduction — tampering
under `vendor/magento/*` is invisible to IC-070. A composer-level
companion check (`IC-072`) is tracked separately in
[#50](https://github.com/IronCartLabs/IronCartM2/issues/50).

## Supported versions

Open `Makefile` and look at `MANIFEST_VERSIONS`. Keep this list in
sync with `Check/PatchLevel/MagentoPatchCatalog::RELEASES`. v2 covers
the latest published patch on each 2.4.x branch — older patches are
out of scope (merchants are expected to be on the latest patch for
PCI compliance anyway).

Adobe Commerce is **not** in this list. The `magento/magento2` source
tree is Open Source; AC merchants get an IC-071 informational finding
from the runtime check, not an IC-070 scan.

## Building a manifest

```bash
make manifests                                # every supported version
make manifests MANIFEST_VERSIONS=2.4.7-p5     # just one tag
```

Under the hood this runs `bin/build-manifest.php --version=<tag>`,
which:

1. Validates the tag against `[A-Za-z0-9._-]+` (refuses anything else
   so a typo'd CLI arg can't end up in a `git clone` flag).
2. `git clone --depth 1 --branch <tag> https://github.com/magento/magento2.git`
   into a tmpdir under `sys_get_temp_dir()`.
3. Recursively walks the clone, skipping `.git/`. Computes SHA-256 for
   every regular file.
4. Sorts entries by relative path so diffs between two manifest
   refreshes are reviewable.
5. Writes `etc/manifests/magento-core-community-<version>.json` with
   the schema documented in
   [`etc/manifests/README.md`](../etc/manifests/README.md).
6. Deletes the tmpdir.

A single manifest is roughly 900 KB. Building one takes a few minutes
on a typical dev box, dominated by the git clone.

## Refresh procedure

When a new Magento patch ships:

1. Update the `MANIFEST_VERSIONS` list in `Makefile`.
2. Refresh `Check/PatchLevel/MagentoPatchCatalog::RELEASES` and
   `data/osv-magento.json` (see `data/README.md`).
3. Run `make manifests` and commit the resulting JSON files.
4. Open a PR — `agent:security` will validate that the new manifests
   came from a clean source tree.

If the source-of-truth ever changes (e.g. Adobe starts publishing an
official manifest, or we switch to a different oracle), the
`source` and `source_ref` fields in every manifest must be updated
too, and `schema_version` bumped via a migration note in
[`Report/ReportBuilder`](../Report/ReportBuilder.php).

## Runtime check behaviour

See `Check/FileIntegrity/CoreFileIntegrityCheck.php` — the contract is
documented in the class header. Briefly:

- **IC-070 HIGH** per altered or missing file, capped at 200.
- **IC-070 CRITICAL** summary when > 200 alterations.
- **IC-070 INFO** scan-complete summary when there are findings but
  the mass-tampering threshold is not hit.
- **IC-071 LOW** when there is no manifest for this (edition,
  version) — e.g. Adobe Commerce or an unsupported community tag.

The check intentionally ignores these path prefixes / exact files
(documented as constants on the class): `var/`, `generated/`,
`pub/static/`, `pub/media/`, `.git/`, `app/etc/env.php`,
`app/etc/config.php`. These are generated at runtime or hold
per-install secrets, and the manifest never lists them anyway. The
prefix list is enforced defensively at scan time in case a future
manifest generator drifts.

Extra files present in the merchant webroot that are not in the
manifest are **not** reported in v2. Marketplace modules legitimately
add files (`app/code/Vendor/Module`, `pub/static/frontend/...`),
making "extras detection" too noisy to ship. This may revisit in v3
with allowlist support.
