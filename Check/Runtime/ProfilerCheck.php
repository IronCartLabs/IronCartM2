<?php

/**
 * IronCart_Scan — IC-024 profiler check.
 *
 * Flags the built-in Magento profiler being enabled in production. The
 * profiler emits per-block timing into response headers/markup and can leak
 * internal class names to anonymous visitors.
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
 * IC-024 — `dev/debug/profiler` must be off in production.
 */
class ProfilerCheck implements CheckInterface
{
    public const ID = 'IC-024';

    private const TITLE = 'Magento profiler enabled in production';
    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-024';

    private const CONFIG_PROFILER = 'dev/debug/profiler';

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
        if ($this->modeReader->mode() !== State::MODE_PRODUCTION) {
            return [];
        }

        $profilerOn = $this->scopeConfig->isSetFlag(
            self::CONFIG_PROFILER,
            ScopeInterface::SCOPE_STORE
        );

        if (!$profilerOn) {
            return [];
        }

        return [[
            'id' => self::ID,
            'title' => self::TITLE,
            'severity' => Severity::MEDIUM,
            'evidence' => [
                self::CONFIG_PROFILER => true,
                'mage_mode' => State::MODE_PRODUCTION,
            ],
            'remediation_url' => self::REMEDIATION_URL,
        ]];
    }
}
