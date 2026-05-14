<?php

/**
 * IronCart_Scan — IC-023 Content-Security-Policy mode check.
 *
 * Inspects the storefront CSP mode (`csp/mode/storefront`). Magento ships a
 * `report-only` default; merchants are expected to harden to `enforced`
 * before launch. A missing value almost always indicates a broken or
 * uninstalled `Magento_Csp` module.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Runtime;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * IC-023 — storefront CSP should be enforced.
 */
class CspModeCheck implements CheckInterface
{
    public const ID = 'IC-023';

    private const MODE_ENFORCED = 'enforced';
    private const MODE_REPORT_ONLY = 'report-only';

    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-023';

    private const CONFIG_STOREFRONT_MODE = 'csp/mode/storefront';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(): array
    {
        $raw = $this->scopeConfig->getValue(
            self::CONFIG_STOREFRONT_MODE,
            ScopeInterface::SCOPE_STORE
        );
        $mode = is_string($raw) ? strtolower(trim($raw)) : '';

        return match ($mode) {
            self::MODE_ENFORCED => [[
                'id' => self::ID,
                'title' => 'Storefront CSP is enforced',
                'severity' => Severity::INFO,
                'evidence' => [self::CONFIG_STOREFRONT_MODE => $mode],
                'remediation_url' => self::REMEDIATION_URL,
            ]],
            self::MODE_REPORT_ONLY => [[
                'id' => self::ID,
                'title' => 'Storefront CSP is in report-only mode',
                'severity' => Severity::MEDIUM,
                'evidence' => [self::CONFIG_STOREFRONT_MODE => $mode],
                'remediation_url' => self::REMEDIATION_URL,
            ]],
            default => [[
                'id' => self::ID,
                'title' => 'Storefront CSP mode is not configured',
                'severity' => Severity::LOW,
                'evidence' => [self::CONFIG_STOREFRONT_MODE => $raw],
                'remediation_url' => self::REMEDIATION_URL,
            ]],
        };
    }
}
