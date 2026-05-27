<?php

/**
 * IronCart_Scan — IC-020 MAGE_MODE check.
 *
 * Flags Magento running in `developer` mode on a host that is not localhost.
 * Developer mode disables view caching, exposes stack traces, and dumps
 * extensive logs — none of which belong on a public store.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Runtime;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Store\Model\ScopeInterface;

/**
 * IC-020 — MAGE_MODE must not be `developer` in production.
 */
class MageModeCheck implements CheckInterface
{
    public const ID = 'IC-020';

    private const TITLE = 'MAGE_MODE set to developer on non-localhost host';
    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-020';

    private const CONFIG_FRONTEND_BASE_URL = 'web/unsecure/base_url';
    private const CONFIG_ADMIN_BASE_URL = 'web/secure/base_url';

    public function __construct(
        private readonly MagentoModeReader $modeReader,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(): array
    {
        $mode = $this->modeReader->mode();
        if ($mode !== State::MODE_DEVELOPER) {
            return [];
        }

        $frontendUrl = (string) $this->scopeConfig->getValue(
            self::CONFIG_FRONTEND_BASE_URL,
            ScopeInterface::SCOPE_STORE
        );
        $adminUrl = (string) $this->scopeConfig->getValue(
            self::CONFIG_ADMIN_BASE_URL,
            ScopeInterface::SCOPE_STORE
        );

        if ($this->isLocalhost($frontendUrl) && $this->isLocalhost($adminUrl)) {
            return [];
        }

        return [[
            'id' => self::ID,
            'title' => self::TITLE,
            'severity' => Severity::CRITICAL,
            'evidence' => [
                'mage_mode' => $mode,
                'frontend_base_url' => $frontendUrl,
                'admin_base_url' => $adminUrl,
            ],
            'remediation_url' => self::REMEDIATION_URL,
        ]];
    }

    /**
     * Treat a URL as "localhost" if its host is `localhost`, `127.0.0.1`,
     * `::1`, or any `*.localhost`/`*.test`/`*.local` development TLD.
     */
    private function isLocalhost(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            return true;
        }

        foreach (['.localhost', '.test', '.local'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
