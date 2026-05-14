# Security Policy

## Reporting a vulnerability

IronCartM2 ships into PCI-scope merchant environments. We take vulnerability reports seriously and ask reporters to follow coordinated disclosure.

**Please report security issues privately to:** `security@ironcart.dev`

Do **not** open a public GitHub issue for security vulnerabilities.

We will:

1. Acknowledge receipt within 2 business days
2. Provide a triage assessment within 5 business days
3. Coordinate a disclosure timeline with the reporter
4. Credit the reporter in the release notes (unless anonymity is requested)

## Scope

In scope:

- Any code in this repository
- Composer package `ironcartlabs/magento-scan`
- Default configuration shipped with the module

Out of scope:

- Vulnerabilities in Magento core, third-party modules, or merchant code that the scanner reports on
- Issues that require a malicious Magento admin with `IronCart_Scan::*` ACL permissions (admin compromise is out of our threat model)

## Supported versions

The latest minor release is supported. Once v1.0 ships, we'll support the latest two minor lines with security patches.
