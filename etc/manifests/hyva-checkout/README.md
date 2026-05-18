# Hyvä Checkout CSP hash manifests

One JSON file per `hyva-themes/magento2-hyva-checkout` release. Each
file lists the SHA-256 hashes of every inline `<script>` block the
shipped checkout templates emit, so IC-911 can detect stale entries
in the merchant's `app/etc/csp_whitelist.xml`.

Refresh process: run `composer require hyva-themes/magento2-hyva-checkout=<ver>`
in a sandbox, extract the inline-script hashes from
`view/frontend/templates/checkout/index.phtml`, and write the file
named `<ver>.json` with the canonical structure shown in `1.1.16.json`.

The shipped seed entries (`PLACEHOLDER-VENDOR-BOOTSTRAP-HASH-*`) exist
so IC-911's unit tests have a reproducible fixture; they are
intentionally not real hashes. The first real Hyvä Checkout release the
manifest covers will replace them and add additional `<ver>.json` files
alongside.
