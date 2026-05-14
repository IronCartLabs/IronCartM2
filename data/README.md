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
3. **Reshape each OSV record** to the schema above. Map OSV's
   `database_specific.severity` (or `severity[].score`) to the
   `critical|high|medium|low` vocabulary used by
   [`Report\Severity`](../Report/Severity.php).
4. **Sort by package then by `id`** so diffs are reviewable, and write
   the result to `data/osv-magento.json`.
5. **Bump `generated_at`** to today's date (UTC).
6. **Refresh `MagentoPatchCatalog::RELEASES`** from the Adobe Commerce
   [release notes](https://experienceleague.adobe.com/en/docs/commerce-operations/release/notes/security-patches/overview)
   so IC-001 keeps grading severity correctly.
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
