<?php

/**
 * IronCart_Scan — shared MAGE_MODE reader.
 *
 * Wraps {@see \Magento\Framework\App\State::getMode()} with a defensive
 * `Throwable → MODE_DEFAULT` fallback so checks that branch on the
 * current Magento application mode do not blow up during bootstrap
 * edge cases (e.g. CLI invocations where the area state has not been
 * initialised yet).
 *
 * Used by:
 *
 *   - {@see \IronCart\Scan\Check\Runtime\ProfilerCheck} (IC-024)
 *   - {@see \IronCart\Scan\Check\Runtime\MageModeCheck} (IC-020)
 *   - {@see \IronCart\Scan\Check\Runtime\Csp\CspReportOnlyInProductionCheck} (IC-084)
 *   - {@see \IronCart\Scan\Check\PwaStudio\GraphQlIntrospectionCheck} (IC-921)
 *
 * Stateless; safe to share. The fallback policy lives here so any future
 * change to "what does the scanner assume when MAGE_MODE is
 * unreadable?" happens in exactly one place.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Runtime;

use Magento\Framework\App\State;
use Throwable;

/**
 * Read-only adapter around {@see State::getMode()} with a
 * {@see State::MODE_DEFAULT} fallback when the underlying call throws.
 */
class MagentoModeReader
{
    public function __construct(
        private readonly State $appState
    ) {
    }

    /**
     * Return the current Magento application mode, or
     * {@see State::MODE_DEFAULT} if `getMode()` throws (typically
     * because the area state has not been initialised yet).
     */
    public function mode(): string
    {
        try {
            return $this->appState->getMode();
        } catch (Throwable) {
            return State::MODE_DEFAULT;
        }
    }
}
