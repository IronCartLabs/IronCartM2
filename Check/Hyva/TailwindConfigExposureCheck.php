<?php

/**
 * IronCart_Scan — IC-910: Hyvä Tailwind config exposed via pub/static.
 *
 * Hyvä's build pipeline keeps `tailwind.config.js`, `tailwind.source.css`,
 * and `postcss.config.js` under the theme directory in `app/design/frontend/`
 * — they should never be served as static assets. A misconfigured deploy
 * (or a stale `pub/static/frontend/**` symlink farm left behind after
 * `bin/magento setup:static-content:deploy`) can leave a copy of these
 * files inside `pub/static/`, which Magento's nginx config happily
 * serves to the public. The Tailwind config in particular leaks the
 * theme's content-glob (custom module paths), purge config, and any
 * inlined design tokens the merchant may have added.
 *
 * Read-only filesystem walk under `<magento_root>/pub/static/frontend/`,
 * bounded to two levels of theme/locale directories so the check stays
 * cheap on large stores. The check only runs when Hyvä is detected;
 * non-Hyvä stores skip silently to keep IC-9xx noise out of Luma scans.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Hyva;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\Finding;
use IronCart\Scan\Report\Severity;

/**
 * IC-910 — Tailwind config file reachable under pub/static.
 */
class TailwindConfigExposureCheck implements CheckInterface
{
    public const ID = 'IC-910';

    public const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-910';

    /**
     * Filenames that must never appear under `pub/static/`.
     *
     * @var list<string>
     */
    private const EXPOSED_FILENAMES = [
        'tailwind.config.js',
        'tailwind.source.css',
        'postcss.config.js',
    ];

    public function __construct(
        private readonly HyvaDetector $detector,
        private readonly ?string $magentoRoot = null
    ) {
    }

    public function run(): array
    {
        if (!$this->detector->isDetected()) {
            return [];
        }

        $root = $this->resolveStaticFrontendRoot();
        if ($root === null) {
            return [];
        }

        $exposed = $this->findExposed($root);
        if ($exposed === []) {
            return [];
        }

        return [
            Finding::make(
                id: self::ID,
                title: sprintf(
                    '%d Hyvä build-config file(s) exposed under pub/static',
                    count($exposed)
                ),
                severity: Severity::MEDIUM,
                evidence: [
                    'exposed_paths' => $exposed,
                    'static_root' => $root,
                ],
                remediationUrl: self::REMEDIATION_URL
            ),
        ];
    }

    /**
     * Walk `pub/static/frontend/<vendor>/<theme>/` (two levels deep) and
     * return any matches against the EXPOSED_FILENAMES list. The walk
     * is bounded so a wrecked deploy with hundreds of stale theme
     * directories does not blow the scan timeout.
     *
     * @return list<string>
     */
    private function findExposed(string $root): array
    {
        $exposed = [];
        $level1 = @scandir($root);
        if ($level1 === false) {
            return [];
        }

        foreach ($level1 as $vendor) {
            if ($vendor === '.' || $vendor === '..') {
                continue;
            }
            $vendorPath = $root . DIRECTORY_SEPARATOR . $vendor;
            if (!is_dir($vendorPath) || is_link($vendorPath)) {
                // Skip non-dirs and chase-resistant symlinks — Hyvä
                // theme dirs are real directories on disk.
                continue;
            }
            $level2 = @scandir($vendorPath);
            if ($level2 === false) {
                continue;
            }
            foreach ($level2 as $theme) {
                if ($theme === '.' || $theme === '..') {
                    continue;
                }
                $themePath = $vendorPath . DIRECTORY_SEPARATOR . $theme;
                if (!is_dir($themePath)) {
                    continue;
                }
                foreach (self::EXPOSED_FILENAMES as $filename) {
                    // Direct hit at the theme root.
                    $candidate = $themePath . DIRECTORY_SEPARATOR . $filename;
                    if (is_file($candidate)) {
                        $exposed[] = $this->relativise($candidate, $root);
                    }
                    // And inside the tailwind/ subdir, which is where
                    // Hyvä's default theme keeps the canonical copy.
                    $tailwindCandidate = $themePath
                        . DIRECTORY_SEPARATOR
                        . 'tailwind'
                        . DIRECTORY_SEPARATOR
                        . $filename;
                    if (is_file($tailwindCandidate)) {
                        $exposed[] = $this->relativise($tailwindCandidate, $root);
                    }
                }
            }
        }

        sort($exposed);
        return array_values(array_unique($exposed));
    }

    /**
     * Render the absolute path relative to the static root so the
     * finding evidence is readable in CI logs and avoids leaking the
     * full filesystem layout of the host.
     */
    private function relativise(string $path, string $root): string
    {
        $rootWithSep = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $rootWithSep)) {
            return 'pub/static/frontend/' . substr($path, strlen($rootWithSep));
        }
        return $path;
    }

    /**
     * Resolve `<magento_root>/pub/static/frontend`. Walks up from the
     * module location to find the Magento root, mirroring the pattern
     * in {@see \IronCart\Scan\Check\PatchLevel\ComposerLockReader}.
     */
    private function resolveStaticFrontendRoot(): ?string
    {
        if ($this->magentoRoot !== null) {
            $candidate = rtrim($this->magentoRoot, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . 'pub'
                . DIRECTORY_SEPARATOR
                . 'static'
                . DIRECTORY_SEPARATOR
                . 'frontend';
            return is_dir($candidate) ? $candidate : null;
        }

        $dir = __DIR__;
        for ($i = 0; $i < 10; $i++) {
            $candidate = $dir
                . DIRECTORY_SEPARATOR
                . 'pub'
                . DIRECTORY_SEPARATOR
                . 'static'
                . DIRECTORY_SEPARATOR
                . 'frontend';
            if (is_dir($candidate)) {
                return $candidate;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return null;
    }
}
