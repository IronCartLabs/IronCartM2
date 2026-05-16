# `bin/magento ironcart:scan --upload`

Optional, opt-in hosted reporting. POSTs the scan output to
[ironcart.dev](https://ironcart.dev) so the operator (and any
collaborators on the same account) can view, share, and track scans
through a hosted dashboard.

**Off by default.** A fresh `composer require ironcartlabs/magento-scan`
install does not make any outbound calls; the scanner continues to run
read-only checks until the operator explicitly enables uploads in admin
config and passes the `--upload` flag.

## Enabling

1. Sign up at [ironcart.dev/scanner](https://ironcart.dev/scanner) — or
   claim an existing anonymous scan — and copy your token from the
   account dashboard.
2. In Magento admin: **Stores → Configuration → Ironcart → Scan → Scan Upload**.
3. Toggle **Enable scan upload to ironcart.dev** to `Yes`.
4. Paste your token into **ironcart.dev upload token** (stored encrypted
   via Magento's standard `Magento\Config\Model\Config\Backend\Encrypted`
   backend, same as `payment/*/private_key` and other operator secrets).
5. Save.

Then run:

```bash
bin/magento ironcart:scan --upload --format=json
```

On success, stdout includes the line:

```
Scan uploaded: https://ironcart.dev/scan/<id>
```

## What gets sent

The payload conforms to schema version `1` of the IronCartWeb ingest contract:

```json
{
  "schema_version": "1",
  "source": "ironcart-magento-scan/<module_version>",
  "store": {
    "base_url": "https://shop.example.com",
    "magento_version": "2.4.7-p3",
    "magento_edition": "community",
    "module_version": "<module_version>",
    "composer_packages": [
      {"name": "magento/product-community-edition", "version": "2.4.7-p3"}
    ]
  },
  "findings": [
    {
      "check_id": "IC-020",
      "severity": "critical",
      "title": "MAGE_MODE set to developer on non-localhost host",
      "evidence": {"mage_mode": "developer", "...": "..."},
      "remediation_url": "https://ironcart.dev/docs/checks/IC-020"
    }
  ]
}
```

Notes on the shape:

- `store.base_url` is normalised to lowercase host with no trailing
  slash. The server disambiguates uploads by `(account_id, base_url)`,
  so a stable canonical form is required.
- `store.magento_edition` is lowercased — `community`, `enterprise`, or
  `cloud`.
- Findings preserve the v0 evidence shape but with the canonical key
  `check_id` (the in-module representation uses `id`).

## What is NEVER sent

- **Admin email.** The module rejects any payload whose tree contains
  a key matching `admin_email`, `operator_email`, `admin_username`, or
  `admin_user_email`. The IronCartWeb ingest endpoint also rejects
  these keys with a 422 — defense in depth.
- **Customer or order PII.** No customer table, no order table, no
  email addresses outside the explicit reject-list. The findings come
  from the read-only check pack, which never touches customer / sales
  data.
- **Secrets from `app/etc/env.php`.** The composer package list comes
  from `composer.lock`, not from `env.php`. Crypt keys, database
  credentials, and queue passwords are never read in the upload path.
- **Cookies or sessions.** The upload client explicitly sets
  `CURLOPT_COOKIE=''` and is a server-to-server call. No browser state
  is carried.

## Hardened transport

The upload client (`Check/Upload/CurlUploadClient.php`) uses ext-curl
directly with the following defense-in-depth options:

| Option | Value | Rationale |
|---|---|---|
| `CURLOPT_FOLLOWLOCATION` | `false` | A 30x to a different host would defeat the host pin. |
| `CURLOPT_MAXREDIRS` | `0` | Belt-and-braces alongside the above. |
| `CURLOPT_PROTOCOLS` | `CURLPROTO_HTTPS` | No HTTP, FTP, file, gopher, dict. |
| `CURLOPT_REDIR_PROTOCOLS` | `CURLPROTO_HTTPS` | Same constraint for would-be redirects. |
| `CURLOPT_SSL_VERIFYPEER` | `true` | Public ironcart.dev — full TLS validation. |
| `CURLOPT_SSL_VERIFYHOST` | `2` | Match certificate against the requested hostname. |
| `CURLOPT_CONNECTTIMEOUT` | `10` | Fail fast if the endpoint is unreachable. |
| `CURLOPT_TIMEOUT` | `60` | Total budget; uploads can be slower than the CVE proxy. |
| `CURLOPT_COOKIE` | `''` | Anonymous server-to-server context. |

In addition, before any socket is opened, the destination URL is parsed
in pure PHP and the host is matched (case-insensitively) against the
`Allowed Host` admin config (default `ironcart.dev`). A misconfigured
endpoint never reaches DNS resolution.

## Size guards

The module short-circuits with a clear "payload would exceed server
limit" message before any socket is opened if either:

- `findings.length > 500`, or
- `composer_packages.length > 1000`.

These bounds mirror the IronCartWeb ingest endpoint's 413 cutoffs.

## Retry policy

- **4xx responses** are NOT retried — they are configuration errors
  (bad token, payload too large, schema mismatch) and retrying just
  spams the server.
- **5xx responses** and **transport timeouts** are retried exactly
  once, with a 2-second backoff.
- After the retry, the command exits non-zero with a stable category
  label on stderr (`auth`, `payload_too_large`, `server`, `timeout`,
  `transport`). The server's response body is NEVER echoed verbatim,
  so a misconfigured IronCartWeb instance cannot accidentally surface
  internal error messages to the operator's terminal.

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Upload succeeded, or upload was correctly skipped because `ironcart_scan/upload/enabled = 0`. |
| `2` | Misconfigured: token missing, payload size guard tripped, host pin rejected the URL, or server returned 401 / 403 / 413. Cron picks this up. |
| `3` | Transport failure: timeout, DNS, TLS, or libcurl error. Try again later or check outbound connectivity. |
| `4` | Server failure: 5xx after retry, or 400 / 422 (schema mismatch — update the module). |

The scan results are still emitted on stdout regardless of the upload
outcome — `--upload` does not gate or modify the scan report itself.

## Advanced: overriding the endpoint (staging / QA)

The Magento admin UI hides the `Endpoint URL` and `Allowed host` fields
unless `Enable scan upload to ironcart.dev` is `Yes`. To run against a
local Next.js dev server (e.g. for testing the IronCartWeb ingest
endpoint before promotion), set both via the CLI:

```bash
bin/magento config:set ironcart_scan/upload/endpoint     "http://127.0.0.1:3000/api/scan/ingest"
bin/magento config:set ironcart_scan/upload/allowed_host "127.0.0.1"
bin/magento config:set ironcart_scan/upload/enabled      1
```

The upload client respects whatever `allowed_host` is configured —
**you cannot trick the production module into uploading to an arbitrary
host without also setting `allowed_host` to match**. This is the
defense-in-depth guard against a runtime admin-config tamper.

To go back to production:

```bash
bin/magento config:set ironcart_scan/upload/endpoint     "https://ironcart.dev/api/scan/ingest"
bin/magento config:set ironcart_scan/upload/allowed_host "ironcart.dev"
```

## Disabling

Set `ironcart_scan/upload/enabled` to `No` in admin. The next
`--upload` invocation prints `Upload disabled (admin → ...). Skipping.`
and exits 0 without opening any socket.

To remove the token entirely:

```bash
bin/magento config:set ironcart_scan/upload/token ''
```

## Schema versioning

This is `schema_version = "1"`. Bumping the schema version requires
coordinated changes on both the module and the IronCartWeb ingest
endpoint; mismatched versions are rejected server-side with a 422 and
the operator-facing message "schema version mismatch — update the
ironcartlabs/magento-scan module".

## Related issues

- [IronCartLabs/IronCartM2#57](https://github.com/IronCartLabs/IronCartM2/issues/57) — module-side `--upload` flag (this doc)
- [IronCartLabs/IronCartWeb#984](https://github.com/IronCartLabs/IronCartWeb/issues/984) — IronCartWeb `/api/scan/ingest` endpoint
- [IronCartLabs/IronCartWeb#982](https://github.com/IronCartLabs/IronCartWeb/issues/982) — v3 scope decisions
- [IronCartLabs/IronCartWeb#884](https://github.com/IronCartLabs/IronCartWeb/issues/884) — tracking epic
