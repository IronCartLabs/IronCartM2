<?php

/**
 * IronCart_Scan — IC-021 secure cookie flags check.
 *
 * Flags stores that do not enforce `HttpOnly` and `Secure` on session
 * cookies. Without either flag, session hijack via XSS or sniffed plaintext
 * is significantly easier.
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
 * IC-021 — `web/cookie/cookie_httponly` and `web/cookie/cookie_secure` must
 * both be on.
 */
class SecureCookieCheck implements CheckInterface
{
    public const ID = 'IC-021';

    private const TITLE = 'Session cookies not enforcing HttpOnly and Secure flags';
    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-021';

    private const CONFIG_HTTP_ONLY = 'web/cookie/cookie_httponly';
    private const CONFIG_SECURE = 'web/cookie/cookie_secure';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(): array
    {
        $httpOnly = $this->scopeConfig->isSetFlag(
            self::CONFIG_HTTP_ONLY,
            ScopeInterface::SCOPE_STORE
        );
        $secure = $this->scopeConfig->isSetFlag(
            self::CONFIG_SECURE,
            ScopeInterface::SCOPE_STORE
        );

        if ($httpOnly && $secure) {
            return [];
        }

        return [[
            'id' => self::ID,
            'title' => self::TITLE,
            'severity' => Severity::HIGH,
            'evidence' => [
                self::CONFIG_HTTP_ONLY => $httpOnly,
                self::CONFIG_SECURE => $secure,
            ],
            'remediation_url' => self::REMEDIATION_URL,
        ]];
    }
}
