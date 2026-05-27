<?php

/**
 * IronCart_Scan — IC-084 CSP in report-only mode while MAGE_MODE=production.
 *
 * Report-only CSP gives operators a false sense of security: it logs
 * violations without blocking them, so an active skimmer continues to
 * exfiltrate while the operator sees "CSP is configured" in the admin.
 * In production this is a frequent misconfig, often left over from a
 * stalled CSP rollout.
 *
 * The check fires when:
 *   - `Content-Security-Policy-Report-Only` is set
 *   - AND `Content-Security-Policy` is NOT set
 *   - AND Magento's app state is `production`
 *
 * If the operator runs report-only outside production (developer mode,
 * default mode) we stay silent — that's the canonical way to gather
 * data before flipping to enforced.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Runtime\Csp;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\Runtime\MagentoModeReader;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\State;

/**
 * IC-084 — CSP must not be report-only in production.
 */
class CspReportOnlyInProductionCheck implements CheckInterface
{
    public const ID = 'IC-084';

    private const TITLE = 'Storefront CSP is in report-only mode with MAGE_MODE=production';
    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-084';

    public function __construct(
        private readonly CspProbeRunner $probeRunner,
        private readonly MagentoModeReader $modeReader
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(): array
    {
        $result = $this->probeRunner->probe();
        if (!$result->probeAttempted) {
            return [];
        }

        if ($result->cspHeader !== null) {
            // Enforced CSP is set — IC-084 only flags the report-only-only case.
            return [];
        }

        if ($result->cspReportOnlyHeader === null) {
            // No CSP at all — IC-080 handles that.
            return [];
        }

        $mode = $this->modeReader->mode();
        if ($mode !== State::MODE_PRODUCTION) {
            return [];
        }

        return [[
            'id' => self::ID,
            'title' => self::TITLE,
            'severity' => Severity::HIGH,
            'evidence' => [
                'probed_url' => $result->probedUrl,
                'mage_mode' => $mode,
                'content_security_policy' => null,
                'content_security_policy_report_only_present' => true,
            ],
            'remediation_url' => self::REMEDIATION_URL,
        ]];
    }
}
