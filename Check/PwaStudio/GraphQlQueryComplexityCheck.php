<?php

/**
 * IronCart_Scan — IC-922: GraphQL query depth / complexity limits.
 *
 * Magento's GraphQL stack ships two backpressure knobs that are the
 * primary defence against expensive nested queries used in CPU-DoS
 * patterns against PWA Studio storefronts:
 *
 *   - `graphql/validation/maximum_query_depth`
 *   - `graphql/validation/maximum_query_complexity`
 *
 * Either of them missing (or set absurdly high — e.g. `100000`) lets
 * a single unauthenticated `/graphql` POST consume disproportionate
 * server time. The check reads the two values via `ScopeConfigInterface`
 * and compares them against safe defaults aligned with Magento's
 * `app/code/Magento/GraphQl/etc/config.xml` 2.4.7+ shipping values
 * (depth ≤ 20, complexity ≤ 300). Values above those (or unset) emit
 * one MEDIUM finding describing the gap.
 *
 * Read-only. Only runs when {@see PwaStudioDetector} reports PWA
 * Studio is present — the same posture is theoretically interesting
 * on Luma/Hyvä stores but those storefronts don't lean on `/graphql`
 * the same way and we'd rather not false-positive there.
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
 * IC-922 — GraphQL query depth / complexity limits missing or too high.
 */
class GraphQlQueryComplexityCheck implements CheckInterface
{
    public const ID = 'IC-922';

    public const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-922';

    public const CONFIG_MAX_DEPTH = 'graphql/validation/maximum_query_depth';
    public const CONFIG_MAX_COMPLEXITY = 'graphql/validation/maximum_query_complexity';

    /**
     * Safe ceilings — anything above these we flag. Aligned with
     * Magento 2.4.7+ shipping defaults; tightening them further is a
     * merchant decision, not the scanner's call.
     */
    public const SAFE_DEPTH_CEILING = 20;
    public const SAFE_COMPLEXITY_CEILING = 300;

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

        $depth = $this->intOrNull($this->scopeConfig->getValue(self::CONFIG_MAX_DEPTH));
        $complexity = $this->intOrNull($this->scopeConfig->getValue(self::CONFIG_MAX_COMPLEXITY));

        $gaps = [];
        if ($depth === null || $depth <= 0) {
            $gaps[] = [
                'config_path' => self::CONFIG_MAX_DEPTH,
                'observed' => $depth,
                'ceiling' => self::SAFE_DEPTH_CEILING,
                'reason' => 'unset_or_invalid',
            ];
        } elseif ($depth > self::SAFE_DEPTH_CEILING) {
            $gaps[] = [
                'config_path' => self::CONFIG_MAX_DEPTH,
                'observed' => $depth,
                'ceiling' => self::SAFE_DEPTH_CEILING,
                'reason' => 'above_ceiling',
            ];
        }

        if ($complexity === null || $complexity <= 0) {
            $gaps[] = [
                'config_path' => self::CONFIG_MAX_COMPLEXITY,
                'observed' => $complexity,
                'ceiling' => self::SAFE_COMPLEXITY_CEILING,
                'reason' => 'unset_or_invalid',
            ];
        } elseif ($complexity > self::SAFE_COMPLEXITY_CEILING) {
            $gaps[] = [
                'config_path' => self::CONFIG_MAX_COMPLEXITY,
                'observed' => $complexity,
                'ceiling' => self::SAFE_COMPLEXITY_CEILING,
                'reason' => 'above_ceiling',
            ];
        }

        if ($gaps === []) {
            return [];
        }

        return [
            Finding::make(
                id: self::ID,
                title: sprintf('%d GraphQL backpressure limit(s) missing or too high', count($gaps)),
                severity: Severity::MEDIUM,
                evidence: [
                    'gaps' => $gaps,
                ],
                remediationUrl: self::REMEDIATION_URL
            ),
        ];
    }

    /**
     * Coerce a raw scope-config value to an int when possible; return
     * null when the value is null / empty / non-numeric. Magento
     * stores ints as strings ("20") so we accept that shape.
     */
    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }
        return null;
    }
}
