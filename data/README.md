# `data/` — bundled scanner inputs

This directory ships read-only reference data the scanner needs at runtime.
Files here are loaded directly from disk; v0 makes **no outbound network
calls**, and that invariant is enforced by code review.

## Files

### `osv-magento.json`

A curated snapshot of [OSV.dev](https://osv.dev/) advisories that affect
Magento Open Source / Adobe Commerce or the Packagist packages typically
installed alongside it. IC-002 (`Check\PatchLevel\ComposerAdvisoryCheck`)
parses `composer.lock` and cross-references each installed package
against this file.

The snapshot must stay **≤ 500 KB on disk** (issue
[#3](https://github.com/IronCartLabs/IronCartM2/issues/3) constraint).
Strip OSV fields that are not consumed by the scanner — keep only `id`,
`aliases`, `summary`, `published`, `severity`, `package`, `affected`
ranges, and `reference`.

**Schema** (`schema_version: "v0"`):

```json
{
  "schema_version": "v0",
  "generated_at": "YYYY-MM-DD",
  "source": "https://osv.dev/",
  "ecosystem": "Packagist",
  "advisories": [
    {
      "id": "GHSA-...",
      "aliases": ["CVE-..."],
      "summary": "...",
      "published": "YYYY-MM-DD",
      "severity": "critical|high|medium|low",
      "package": "vendor/name",
      "affected": [
        {"introduced": "0", "fixed": "1.2.3"}
      ],
      "reference": "https://..."
    }
  ]
}
```

### `MagentoPatchCatalog.php` (in `Check/PatchLevel/`)

The same v0 bundling rule applies to the in-code patch catalogue used by
IC-001. Refresh both at the same time so reported "days behind latest"
numbers stay consistent.

## Manual refresh procedure (v0)

Automation lands in v2 (tracked in the `agent:ops` queue). Until then,
maintainers refresh the snapshot by hand:

1. **Pull the Packagist ecosystem dump** from
   [https://osv-vulnerabilities.storage.googleapis.com/Packagist/all.zip](https://osv-vulnerabilities.storage.googleapis.com/Packagist/all.zip)
   and extract it locally. (You can `curl` it from your dev box — the
   scanner itself must not.)
2. **Filter to Magento-relevant packages.** A practical seed list:
   - `magento/product-community-edition`
   - `magento/product-enterprise-edition`
   - `magento/framework`
   - `magento/module-*`
   - the top-100 transitive deps shown by
     `composer show --tree` on a stock 2.4.7 install (Symfony, Guzzle,
     Laminas, Monolog, Smarty, TinyMCE, CKEditor, Dompdf, phpseclib, …).
   The 2026-10-21 refresh used every package in `magento/magento2`'s own
   `composer.lock` (`packages` + `packages-dev`) at the newest tag of each
   supported line (`2.4.7-p10`, `2.4.8-p5`, `2.4.9`) as the third-party
   seed list.
3. **Reshape each OSV record** to the schema above. Map OSV's
   `database_specific.severity` (or `severity[].score`) to the
   `critical|high|medium|low` vocabulary used by
   [`Report\Severity`](../Report/Severity.php). Skip withdrawn records.
   Turn each OSV `events` sequence into `{introduced, fixed}` /
   `{introduced, last_affected}` pairs. Turn a `versions`-only entry into
   one `{introduced: v, last_affected: v}` pair per version.

   Magento core needs three extra rules. Without them IC-002 either misses
   core advisories or flags fully patched stores:

   - **Remap `magento/community-edition` → `magento/product-community-edition`.**
     GHSA files core advisories under the `magento/magento2` root package
     name, which never appears in a merchant `composer.lock`. The
     metapackage uses the same version numbers.
   - **Clip ranges to one release line.** GHSA ranges often span lines
     (e.g. `introduced 0, fixed 2.4.5-p14`). `version_compare()` orders
     every `2.4.4-pN` below `2.4.5`, so an unclipped range flags a fully
     patched 2.4.4 store. Clip each range to the line of its upper bound.
     For each older line (≥ 2.4.4) that the range covered and that has no
     range of its own, add a range ending at the release Adobe shipped
     for that line in the same patch cycle. Same-cycle peers come from the
     [Released versions](https://experienceleague.adobe.com/en/docs/commerce-operations/release/versions)
     page. Drop ranges that end before 2.4.4.
   - **Drop stale single-version points.** Drop a `versions`-only point
     when a range on the same line already ends below it (e.g. GHSA lists
     a bare `2.4.9` on an advisory fixed in `2.4.9-alpha3`).

   Sanity check: the latest release on every supported line must produce
   zero `magento/product-community-edition` matches.

   Fixes that Adobe ships only as **isolated security patch files** (no
   `-pN` release) have no `fixed` Composer version, so they cannot be
   represented here. Leave them to IC-001 and the file-signature hotfix
   checks.
4. **Sort by package then by `id`** so diffs are reviewable, and write
   the result to `data/osv-magento.json`.
5. **Bump `generated_at`** to today's date (UTC).
6. **Refresh `MagentoPatchCatalog::RELEASES`** from the Adobe Commerce
   [Released versions](https://experienceleague.adobe.com/en/docs/commerce-operations/release/versions)
   page so IC-001 keeps grading severity correctly. Catalogue only Composer
   versions (`X.Y.Z` / `X.Y.Z-pN`). Never add isolated-patch labels such as
   `2.4.9-2026-sep`: a unit test enforces the key format.
7. **Verify size**:

   ```bash
   wc -c data/osv-magento.json   # must be <= 512000
   ```

8. **Run the unit tests**:

   ```bash
   composer test
   ```

9. **Open a PR labelled `agent:dev`**.

If the snapshot ever exceeds 500 KB, trim packages outside the
"installed on a stock 2.4.x merchant" set rather than dropping advisory
fields; reviewers rely on `summary` and `reference` to validate each
finding.
