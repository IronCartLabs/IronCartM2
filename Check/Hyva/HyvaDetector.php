<?php

/**
 * IronCart_Scan — Hyvä storefront detection helper.
 *
 * The IronCart scanner's existing check pack assumes a Luma-shaped
 * storefront (KnockoutJS checkout, `app/design/frontend/**` layouts,
 * the canonical Magento CSP whitelist). Hyvä is the dominant modern
 * Magento 2 frontend; merchants running it have a different attack
 * surface (TailwindCSS-driven assets, AlpineJS checkout, separate
 * Hyvä module ecosystem). Several IronCart checks need to know
 * whether Hyvä is present so they can adapt — and so a Hyvä-specific
 * check pack (IC-910..IC-912) can register findings against the
 * Hyvä-shaped surface.
 *
 * Detection is read-only and ordered cheapest-first:
 *
 *   1. Is the `Hyva_Theme` Magento module registered?
 *   2. Does the project's `composer.lock` mention `hyva-themes/*`
 *      packages?
 *
 * Either signal is sufficient — both are listed in the evidence
 * payload so downstream checks can disambiguate (e.g. a developer
 * may have a Hyvä child theme installed via composer but not yet
 * enabled). The detection result is cached for the lifetime of the
 * scan run so multiple Hyvä-aware checks pay the lookup cost once.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Hyva;

use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use Magento\Framework\Module\ModuleListInterface;
use Throwable;

/**
 * Detects whether the current Magento install is running the Hyvä frontend.
 *
 * Read-only — composes existing readers (ModuleListInterface,
 * ComposerLockReader) and never opens a network socket, never reads
 * customer/order PII, and never mutates state. Results are memoised
 * inside the per-request DI singleton so the three Hyvä-aware checks
 * (IC-910/IC-911/IC-912) all see the same answer.
 */
class HyvaDetector
{
    public const HYVA_MODULE_NAME = 'Hyva_Theme';

    /**
     * Composer namespace prefix for the Hyvä ecosystem. Both the core
     * theme (`hyva-themes/magento2-default-theme`) and every official
     * Hyvä module (checkout, compat shims, etc.) ship under it.
     */
    public const COMPOSER_VENDOR_PREFIX = 'hyva-themes/';

    /**
     * Cached detection result; `null` means not-yet-computed.
     *
     * @var array{
     *     detected:bool,
     *     signals:array{module:bool, composer:bool},
     *     hyva_packages:array<string,string>
     * }|null
     */
    private ?array $cached = null;

    public function __construct(
        private readonly ModuleListInterface $moduleList,
        private readonly ComposerLockReader $lockReader
    ) {
    }

    /**
     * Return whether Hyvä is detected. Cheap; cached.
     */
    public function isDetected(): bool
    {
        return $this->detect()['detected'];
    }

    /**
     * Return the installed `hyva-themes/*` composer packages as a
     * name → normalised-version map. Empty when none are installed
     * (or when `composer.lock` is unreadable).
     *
     * @return array<string,string>
     */
    public function hyvaPackages(): array
    {
        return $this->detect()['hyva_packages'];
    }

    /**
     * Full detection record — used by IC-910's "Hyvä detection context"
     * evidence payload and the scan-session context flag.
     *
     * @return array{
     *     detected:bool,
     *     signals:array{module:bool, composer:bool},
     *     hyva_packages:array<string,string>
     * }
     */
    public function detect(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $moduleSignal = $this->moduleSignal();
        $hyvaPackages = $this->hyvaPackagesFromLock();
        $composerSignal = $hyvaPackages !== [];

        $this->cached = [
            'detected' => $moduleSignal || $composerSignal,
            'signals' => [
                'module' => $moduleSignal,
                'composer' => $composerSignal,
            ],
            'hyva_packages' => $hyvaPackages,
        ];

        return $this->cached;
    }

    /**
     * Reset the memoised result. Used by tests; not called in production.
     */
    public function reset(): void
    {
        $this->cached = null;
    }

    /**
     * Module-level signal: is the `Hyva_Theme` module registered in
     * Magento's module list? `getOne()` returns `null` when the
     * module isn't installed at all; we treat the array shape as
     * "module present" regardless of `setup_version`.
     */
    private function moduleSignal(): bool
    {
        try {
            $module = $this->moduleList->getOne(self::HYVA_MODULE_NAME);
        } catch (Throwable) {
            return false;
        }
        return is_array($module) && $module !== [];
    }

    /**
     * Composer-level signal: which `hyva-themes/*` packages are
     * declared in `composer.lock`? Returns an empty array when the
     * lockfile is unavailable — that's a "no signal", not a hard
     * failure, because non-Hyvä stores legitimately have no Hyvä
     * packages.
     *
     * @return array<string,string>
     */
    private function hyvaPackagesFromLock(): array
    {
        try {
            $installed = $this->lockReader->packages();
        } catch (Throwable) {
            return [];
        }

        $matches = [];
        foreach ($installed as $name => $version) {
            if (str_starts_with($name, self::COMPOSER_VENDOR_PREFIX)) {
                $matches[$name] = $version;
            }
        }
        ksort($matches);
        return $matches;
    }
}
