<?php

/**
 * IronCart_Scan — IC-010: admin URL frontname.
 *
 * Flags the admin URL frontname when left at the Magento default (`admin`),
 * which makes the backend trivially discoverable for credential-stuffing and
 * brute-force traffic. A custom frontname is rendered as an informational
 * finding so the report still surfaces the configured value.
 *
 * Reads the frontname from `core_config_data` via `ScopeConfigInterface`. No
 * raw SQL, no DB writes.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Admin;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * IC-010: admin URL frontname posture.
 */
class AdminUrlFrontnameCheck implements CheckInterface
{
    public const ID = 'IC-010';

    /**
     * The Magento-default frontname. Hard-coded here rather than imported from
     * `Magento\Backend\Helper\Data::BACKEND_AREA_FRONTNAME_CONFIG_PATH` to keep
     * this class free of unrelated coupling.
     */
    public const DEFAULT_FRONTNAME = 'admin';

    public const CONFIG_PATH = 'admin/url/custom_path';
    public const USE_CUSTOM_PATH = 'admin/url/use_custom_path';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function run(): array
    {
        $useCustom = (bool) $this->scopeConfig->getValue(self::USE_CUSTOM_PATH);
        $configured = $this->scopeConfig->getValue(self::CONFIG_PATH);
        $frontname = (is_string($configured) && $configured !== '' && $useCustom)
            ? $configured
            : self::DEFAULT_FRONTNAME;

        $isDefault = ($frontname === self::DEFAULT_FRONTNAME);

        return [[
            'id' => self::ID,
            'title' => $isDefault
                ? 'Admin URL frontname is the default ("admin")'
                : 'Admin URL frontname is customised',
            'severity' => $isDefault ? Severity::HIGH : Severity::INFO,
            'evidence' => [
                'frontname' => $frontname,
                'is_default' => $isDefault,
            ],
            'remediation_url' => 'https://ironcart.dev/docs/checks/IC-010',
        ]];
    }
}
