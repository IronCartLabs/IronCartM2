<?php

/**
 * IronCart_Scan — IC-923: GraphQL CORS allows wildcard origin.
 *
 * PWA Studio storefronts almost always run on a different origin to
 * the Magento backend (Vercel-hosted `shop.example.com` talking to
 * `magento.example.com/graphql`). The merchant has to tell Magento
 * which origins may issue credentialed GraphQL requests via
 * `web/graphql/cors_allowed_origins` — and the path of least
 * resistance under deadline pressure is to set it to `*`.
 *
 * Wildcard CORS on `/graphql` is materially worse than wildcard CORS
 * on a generic REST endpoint: PWA Studio's Apollo client routinely
 * sends `Authorization: Bearer <customer-token>` headers, and a
 * misconfigured wildcard allowance lets any third-party site issue
 * authenticated customer queries / mutations from the visitor's
 * browser. Cart hijacking, order enumeration, customer profile reads
 * all become viable from a benign-looking external page.
 *
 * Two related config paths cover the surface depending on Magento
 * minor version:
 *
 *   - `web/graphql/cors_allowed_origins` (post-2.4.4 canonical)
 *   - `web/graphql/cors_max_age` (orthogonal — not checked here)
 *
 * We flag the wildcard `*`, the literal string `null`, and the
 * common typo `"*"` (with quotes baked into the value, observed in
 * one support ticket). Empty / unset is fine — Magento defaults to
 * no CORS exposure.
 *
 * Read-only via `ScopeConfigInterface`. Only runs when
 * {@see PwaStudioDetector} reports PWA Studio is present — Luma
 * stores don't ship the `/graphql` consumer pattern so the false-
 * positive rate is too high there.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\PwaStudio;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\Finding;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * IC-923 — GraphQL CORS allows any origin.
 */
class GraphQlCorsWildcardCheck implements CheckInterface
{
    public const ID = 'IC-923';

    public const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-923';

    public const CONFIG_CORS_ALLOWED_ORIGINS = 'web/graphql/cors_allowed_origins';

    public function __construct(
        private readonly PwaStudioDetector $detector,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function run(): array
    {
        if (!$this->detector->isDetected()) {
            return [];
        }

        $value = $this->scopeConfig->getValue(self::CONFIG_CORS_ALLOWED_ORIGINS);
        if (!is_string($value) || $value === '') {
            // Unset means "no CORS exposure" — safe default.
            return [];
        }

        $entries = $this->parseOrigins($value);
        $wildcards = [];
        foreach ($entries as $entry) {
            if ($this->isWildcardOrigin($entry)) {
                $wildcards[] = $entry;
            }
        }

        if ($wildcards === []) {
            return [];
        }

        return [
            Finding::make(
                id: self::ID,
                title: 'GraphQL CORS allowed-origins includes a wildcard',
                severity: Severity::HIGH,
                evidence: [
                    'config_path' => self::CONFIG_CORS_ALLOWED_ORIGINS,
                    'raw_value' => $value,
                    'wildcard_entries' => $wildcards,
                ],
                remediationUrl: self::REMEDIATION_URL
            ),
        ];
    }

    /**
     * The admin field is a comma-separated origin list. Split + trim
     * and drop empties; preserve the original casing so the evidence
     * payload is recognisable to the operator.
     *
     * @return list<string>
     */
    private function parseOrigins(string $value): array
    {
        $parts = preg_split('/[,\s]+/', $value) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $clean = trim((string) $part);
            if ($clean === '') {
                continue;
            }
            $out[] = $clean;
        }
        return $out;
    }

    private function isWildcardOrigin(string $entry): bool
    {
        $entryNorm = strtolower(trim($entry, " \t\n\r\0\x0B\"'"));
        if ($entryNorm === '*' || $entryNorm === 'null') {
            return true;
        }
        // `*.example.com` is a host-pattern allowlist that Magento does
        // NOT natively expand — when present it usually indicates the
        // operator tried to express "any subdomain" and ended up with
        // an effective wildcard. Flag it so the operator confirms intent.
        if (str_starts_with($entryNorm, '*.')) {
            return true;
        }
        return false;
    }
}
