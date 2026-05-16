<?php

/**
 * IronCart_Scan — IC-085 storefront base URL appears unconfigured.
 *
 * Magento's `setup:install` defaults `web/unsecure/base_url` to
 * `http://example.com/`. A live store that still resolves to
 * `example.com` is almost always a half-finished install — the
 * storefront either is unreachable or is hitting Magento's "Internet
 * Assigned Numbers Authority" placeholder. This check exists so the
 * operator gets a clear LOW-severity nudge instead of a confusing
 * "probe skipped" silence from the rest of the IC-08x pack.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Runtime\Csp;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Report\Severity;

/**
 * IC-085 — storefront base URL must not be the `example.com` default.
 */
class CspBaseUrlConfiguredCheck implements CheckInterface
{
    public const ID = 'IC-085';

    private const TITLE = 'Storefront base URL appears unconfigured (default example.com)';
    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-085';

    public function __construct(
        private readonly CspProbeRunner $probeRunner
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(): array
    {
        $result = $this->probeRunner->probe();
        if ($result->skipReason !== CspProbeRunner::SKIP_UNCONFIGURED_BASE_URL) {
            return [];
        }

        return [[
            'id' => self::ID,
            'title' => self::TITLE,
            'severity' => Severity::LOW,
            'evidence' => [
                'configured_base_url' => $result->probedUrl,
            ],
            'remediation_url' => self::REMEDIATION_URL,
        ]];
    }
}
