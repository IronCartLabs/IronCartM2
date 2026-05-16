# `etc/manifests/` — IC-070 file-integrity manifests

This directory holds the file-hash manifests consumed at runtime by
[`Check/FileIntegrity/CoreFileIntegrityCheck`](../../Check/FileIntegrity/CoreFileIntegrityCheck.php)
(IC-070). One manifest per supported Magento Open Source patch release,
filed as `magento-core-community-<version>.json`.

The scanner only **reads** these files. The check is read-only and
makes no outbound network calls — the manifest itself is the source of
truth and is built ahead of release.

## What's a manifest

A flat JSON object mapping every relative path in the
`magento/magento2` source tree at a given tag to its SHA-256 hex digest:

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

The manifest is **ours** — Adobe does not publish a file-hash manifest.
See [`docs/manifests.md`](../../docs/manifests.md) and
[#47](https://github.com/IronCartLabs/IronCartM2/issues/47) for the
rationale.

## Edition coverage

Only `community` (Magento Open Source) is supported in v2. Adobe
Commerce coverage requires paid composer auth at build time and is
deferred to the v3 hosted backend, which can fetch manifests
server-side. Merchants running Adobe Commerce receive the IC-071
informational finding ("manifest not available for this edition")
rather than an IC-070 scan.

## Generating manifests

```bash
make manifests                    # build for every supported version
make manifests MANIFEST_VERSIONS=2.4.7-p5  # one specific tag
```

The generator (`bin/build-manifest.php`) shallow-clones
`magento/magento2` at the requested tag, walks the tree, computes
SHA-256 per file, sorts entries, and writes JSON here. Requires `git`
and `php` on the PATH; takes a few minutes per tag on a typical dev
box, dominated by the clone.

## When to refresh

- A new Magento Open Source patch ships (then also update
  `Check/PatchLevel/MagentoPatchCatalog::RELEASES`).
- The supported-version list in the project Makefile changes
  (`MANIFEST_VERSIONS`).

## Size

A single manifest is roughly 900 KB on disk
(~6000 files × ~150 bytes per entry, pretty-printed). The combined set
fits comfortably in the module's repo without bloating composer
installs.
