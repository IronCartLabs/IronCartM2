<?php

/**
 * IronCart_Scan — IC-921: GraphQL introspection enabled in production.
 *
 * Magento exposes a single `/graphql` endpoint that PWA Studio storefronts
 * (and any other headless client) drive. Magento 2.4.5+ ships a config
 * flag `graphql/validation/disable_introspection` (admin label
 * "Disable GraphQL introspection") that, when set to `1`, refuses
 * `__schema` / `__type` queries from non-Adobe-IMS clients in production.
 * When the flag is `0` while `MAGE_MODE === 'production'`, every
 * unauthenticated visitor can enumerate the full GraphQL schema —
 * including custom merchant-installed extensions — which materially
 * lowers the cost of finding insecure queries downstream.
 *
 * Read-only:
 *
 *   - Reads the config value via the standard `ScopeConfigInterface`.
 *   - Reads `MAGE_MODE` via `State::getMode()`.
 *
 * Only runs when the {@see PwaStudioDetector} reports PWA Studio is
 * present; Luma / Hyvä stores typically scope this differently (they
 * may rely on introspection in dev tooling that isn't exposed
 * publicly), so we don't claim coverage outside the PWA pack.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\PwaStudio;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\Finding;
use IronCart\Scan\Check\Runtime\MagentoModeReader;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;

/**
 * IC-921 — GraphQL introspection allowed in production.
 */
class GraphQlIntrospectionCheck implements CheckInterface
{
    public const ID = 'IC-921';

    public const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-921';

    /**
     * Magento's config path for the post-2.4.5 introspection toggle.
     * `0` = introspection allowed; `1` = disabled in production.
     */
    public const CONFIG_DISABLE_INTROSPECTION = 'graphql/validation/disable_introspection';

    public function __construct(
        private readonly PwaStudioDetector $detector,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly MagentoModeReader $modeReader
    ) {
    }

    public function run(): array
    {
        if (!$this->detector->isDetected()) {
            return [];
        }

        $mode = $this->modeReader->mode();
        if ($mode !== State::MODE_PRODUCTION) {
            // Introspection is fine (and arguably desirable) in
            // developer / default modes — the risk is only the
            // public production endpoint.
            return [];
        }

        $rawValue = $this->scopeConfig->getValue(self::CONFIG_DISABLE_INTROSPECTION);
        // Magento stores config bools as "0" / "1" strings. Anything
        // that is not exactly `1` / `true` we treat as "introspection
        // is allowed" because that matches Magento's own evaluation
        // path in `\Magento\GraphQl\Model\Backpressure\BackpressureContextFactory`.
        $disabled = $rawValue === '1' || $rawValue === 1 || $rawValue === true;
        if ($disabled) {
            return [];
        }

        return [
            Finding::make(
                id: self::ID,
                title: 'GraphQL introspection enabled while MAGE_MODE=production',
                severity: Severity::MEDIUM,
                evidence: [
                    'mage_mode' => $mode,
                    'config_path' => self::CONFIG_DISABLE_INTROSPECTION,
                    'config_value' => $rawValue,
                ],
                remediationUrl: self::REMEDIATION_URL
            ),
        ];
    }
}
