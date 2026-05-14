<?php

/**
 * IronCart_Scan — IC-022 HTTPS enforcement check.
 *
 * Flags stores that do not enforce HTTPS for the storefront and admin.
 * Admin-without-HTTPS is treated as `critical`; storefront-only gaps are
 * `high`.
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
 * IC-022 — `web/secure/use_in_frontend` and `web/secure/use_in_adminhtml`
 * must be enabled.
 */
class HttpsEnforcementCheck implements CheckInterface
{
    public const ID = 'IC-022';

    private const TITLE_ADMIN = 'HTTPS not enforced for the Magento admin';
    private const TITLE_FRONTEND = 'HTTPS not enforced for the storefront';
    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-022';

    private const CONFIG_FRONTEND = 'web/secure/use_in_frontend';
    private const CONFIG_ADMIN = 'web/secure/use_in_adminhtml';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(): array
    {
        $frontend = $this->scopeConfig->isSetFlag(
            self::CONFIG_FRONTEND,
            ScopeInterface::SCOPE_STORE
        );
        $admin = $this->scopeConfig->isSetFlag(
            self::CONFIG_ADMIN,
            ScopeInterface::SCOPE_STORE
        );

        $findings = [];

        if (!$admin) {
            $findings[] = [
                'id' => self::ID,
                'title' => self::TITLE_ADMIN,
                'severity' => Severity::CRITICAL,
                'evidence' => [
                    self::CONFIG_ADMIN => $admin,
                ],
                'remediation_url' => self::REMEDIATION_URL,
            ];
        }

        if (!$frontend) {
            $findings[] = [
                'id' => self::ID,
                'title' => self::TITLE_FRONTEND,
                'severity' => Severity::HIGH,
                'evidence' => [
                    self::CONFIG_FRONTEND => $frontend,
                ],
                'remediation_url' => self::REMEDIATION_URL,
            ];
        }

        return $findings;
    }
}
