<?php

/**
 * IronCart_Scan — per-run scan session state.
 *
 * Mutable, request-scoped value holder for operator-controlled flags forwarded
 * from `bin/magento ironcart:scan`. Today this is only the `--include-usernames`
 * opt-in (used by the admin-posture check pack to gate PII), but new flags can
 * be added here without changing the {@see CheckInterface} signature defined
 * in #6.
 *
 * `ScanCommand` sets the flags from CLI input on the DI-injected singleton
 * before invoking {@see CheckRegistry::runAll()}; checks read the flag values
 * during {@see CheckInterface::run()}. This indirection keeps the check
 * contract arg-free while still letting individual checks honour per-run
 * operator preferences.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check;

/**
 * Mutable holder for the operator-supplied flags of a single scan run.
 *
 * Registered as a Magento DI singleton so every check sees the same instance.
 * Defaults are deliberately safe (no PII) — opt-in only.
 */
class ScanSession
{
    private bool $includeUsernames = false;

    /**
     * Default of `true` is the v1.x announce-before-remove posture
     * (issue #83): deprecated checks still run, the operator only sees a
     * stderr notice telling them v2.0.0 will remove them. The flip to
     * `false` lands in a separate v2.0.0 ticket — not this PR.
     */
    private bool $includeDeprecated = true;

    public function setIncludeUsernames(bool $value): void
    {
        $this->includeUsernames = $value;
    }

    public function includeUsernames(): bool
    {
        return $this->includeUsernames;
    }

    public function setIncludeDeprecated(bool $value): void
    {
        $this->includeDeprecated = $value;
    }

    public function includeDeprecated(): bool
    {
        return $this->includeDeprecated;
    }
}
