# `etc/manifests/` — bundled file-integrity manifests

This directory holds two families of file-integrity manifests, both consumed
at runtime by the `Check/FileIntegrity/` pack:

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
informational findings ("manifest not available for this edition")
rather than an IC-070 / IC-072 scan.

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
`composer create-project --no-install` for the requested tag, parses
the resulting `composer.lock`, harvests `dist.shasum` per package,
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

An IC-070 manifest is roughly 900 KB on disk (~6000 files × ~150 bytes
per entry, pretty-printed). An IC-072 manifest is far smaller — closer
to 60 KB (~400 packages × ~120 bytes per entry). The combined set fits
comfortably in the module's repo without bloating composer installs.
