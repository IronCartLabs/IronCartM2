# `etc/manifests/` — file-integrity manifests

## Status: no IC-070 / IC-072 manifest ships yet

As of v1.6.3 this directory holds only the Hyvä manifests (`hyva-checkout/`,
`hyva-modules/`). No `magento-core-community-*.json` or
`composer-sha1-community-*.json` file has ever been committed or released
([#236](https://github.com/IronCartLabs/IronCartM2/issues/236)). At runtime
that means:

- IC-070 and IC-072 never run. Every scan reports IC-071 and IC-073 with
  `evidence.reason = "no_manifests_bundled"` and `evidence.checked = false`.
- Those two findings are a coverage gap. They do not mean core files or
  Composer packages were verified.

The generators described below exist, but their output must not be committed
until these are resolved:

- **IC-070 path model.** `bin/build-manifest.php` hashes the
  `magento/magento2` git tree, where modules live under `app/code/Magento/`
  and the framework under `lib/internal/Magento/Framework/`. A Composer
  install (what `composer create-project` produces, and the normal production
  layout) has those files under `vendor/magento/*` instead. The check resolves
  every manifest path against the Magento root, so on a Composer install each
  of those entries would be reported as a missing core file and a clean store
  would get the CRITICAL mass-tampering finding. At tag `2.4.7-p10` that is
  26,560 files under `app/code/` and 4,380 under `lib/internal/`.
- **IC-070 exact-version match.** A manifest only applies to the exact
  version string it was built for. `MANIFEST_VERSIONS` lists the latest patch
  of each supported line, so a store one patch behind would still get IC-071.
- **IC-072 generation.** `bin/build-composer-manifest.php` needs
  repo.magento.com credentials, which exist only as CI secrets. The
  `composer-manifests` workflow that runs it has not completed a run yet.

The rest of this file documents the intended design.

## Manifest families

Two families of file-integrity manifests are designed to live here, both
consumed at runtime by the `Check/FileIntegrity/` pack:

- `magento-core-community-<version>.json` — IC-070 (SHA-256 per file across the
  `magento/magento2` source tree). Built by
  [`bin/build-manifest.php`](../../bin/build-manifest.php), invoked from
  `make core-manifests`. Loaded by
  [`ManifestRepository`](../../Check/FileIntegrity/ManifestRepository.php).
- `composer-sha1-community-<version>.json` — IC-072 (SHA-1 per package's
  `dist.shasum` from a clean composer create-project). Built by
  [`bin/build-composer-manifest.php`](../../bin/build-composer-manifest.php),
  invoked from `make composer-manifests`. Loaded by
  [`ComposerLockManifestRepository`](../../Check/FileIntegrity/ComposerLockManifestRepository.php).

The scanner only **reads** these files. The two integrity checks are
read-only and make no outbound network calls — the manifests themselves
are the source of truth and are built ahead of release.

## What's a manifest

### IC-070 — core-file manifest

A flat JSON object mapping every relative path in the `magento/magento2`
source tree at a given tag to its SHA-256 hex digest:

```json
{
  "schema_version": "v0",
  "edition": "community",
  "version": "2.4.7-p5",
  "source": "https://github.com/magento/magento2.git",
  "source_ref": "2.4.7-p5",
  "generated_at": "2026-05-16",
  "algorithm": "sha256",
  "entries": {
    "app/bootstrap.php": "abcd...ef",
    "pub/index.php": "1234...90"
  }
}
```

### IC-072 — composer-lock manifest

A flat JSON object mapping every `<vendor>/<package>` recorded in a clean
`composer create-project magento/project-community-edition:<version>`
lockfile to the `dist.shasum` (SHA-1 hex) Composer downloaded the package
against:

```json
{
  "schema_version": "v0",
  "edition": "community",
  "version": "2.4.7-p5",
  "source": "composer create-project magento/project-community-edition",
  "source_ref": "2.4.7-p5",
  "generated_at": "2026-05-16",
  "algorithm": "sha1",
  "entries": {
    "magento/framework": "abcd...90",
    "magento/module-catalog": "0123...ef"
  }
}
```

Both manifests are **ours** — Adobe does not publish either a file-hash
manifest or a composer-package-shasum manifest. See
[`docs/manifests.md`](../../docs/manifests.md),
[#47](https://github.com/IronCartLabs/IronCartM2/issues/47), and
[#50](https://github.com/IronCartLabs/IronCartM2/issues/50) for the
rationale.

## Edition coverage

Only `community` (Magento Open Source) is supported in v2 for either
manifest. Adobe Commerce coverage requires paid composer auth at build
time and is deferred to the v3 hosted backend, which can fetch manifests
server-side. Merchants running Adobe Commerce receive IC-071 / IC-073
"not checked" findings rather than an IC-070 / IC-072 scan. Once manifests
ship, `evidence.reason` for them is `unsupported_edition`; until then every
edition gets `no_manifests_bundled`.

## Generating manifests

```bash
# Both families for every supported version
make manifests

# IC-070 only (shallow-clones magento/magento2 per tag)
make core-manifests

# IC-072 only (composer create-project per tag — no install)
make composer-manifests

# A single tag (applies to whichever target is invoked)
make composer-manifests MANIFEST_VERSIONS=2.4.7-p5
```

The IC-070 generator (`bin/build-manifest.php`) shallow-clones
`magento/magento2` at the requested tag, walks the tree, computes
SHA-256 per file, sorts entries, and writes JSON here. Requires `git`
and `php` on the PATH; takes a few minutes per tag on a typical dev
box, dominated by the clone.

The IC-072 generator (`bin/build-composer-manifest.php`) runs
`composer create-project --no-install` for the requested tag against
repo.magento.com, then `composer update --no-install` to write
`composer.lock`, parses it, harvests `dist.shasum` per package,
sorts entries, and writes JSON here. Requires `composer` and `php` on
the PATH plus repo.magento.com Composer auth; finishes in roughly a
minute per tag (lockfile-only resolve, no vendor extraction).

## When to refresh

- A new Magento Open Source patch ships (then also update
  `Check/PatchLevel/MagentoPatchCatalog::RELEASES`).
- The supported-version list in the project Makefile changes
  (`MANIFEST_VERSIONS`).

Refresh both families together — they share the version list and a
divergent set will surface as IC-071 / IC-073 informational findings
for the half that wasn't rebuilt.

## Size

No manifest has been built yet, so these are estimates. The
`magento/magento2` tree at tag `2.4.7-p10` has more than 40,000 files, so
an IC-070 manifest built from it would be several MB per version at
~150 bytes per pretty-printed entry, not the ~900 KB (~6000 files) this
file used to state. An IC-072 manifest is far smaller: closer to 60 KB
(~400 packages × ~120 bytes per entry). Measure both before committing a
set, because they ship in every composer install of the module.
