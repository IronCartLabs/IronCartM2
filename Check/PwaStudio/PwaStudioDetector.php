<?php

/**
 * IronCart_Scan — PWA Studio storefront detection helper.
 *
 * Adobe / Magento PWA Studio (Venia) is a headless React storefront that
 * talks to Magento through the `/graphql` endpoint. Stores running
 * PWA Studio have a fundamentally different attack surface to the
 * classic Luma frontend and even to Hyvä: there is no PHTML render
 * path, the entire storefront is one SPA, and the security posture
 * is dominated by what `/graphql` exposes.
 *
 * Detection is read-only and ordered cheapest-first:
 *
 *   1. Is a PWA Studio composer module installed?
 *      - `magento/module-pwa` (Adobe's PWA core module — historical
 *        meta-package)
 *      - `magento/pwa` (the PWA Studio scaffolding meta-package some
 *        merchants pull via composer)
 *   2. Does the Magento root contain a `package.json` whose
 *      `dependencies` (or `devDependencies`) reference any of the
 *      `@magento/pwa-studio` / `@magento/venia-ui` / `@magento/peregrine`
 *      packages? This catches the standard "Venia checkout cloned
 *      into a sibling directory" deployment.
 *   3. Does a `pwa-studio.config.json`, `venia.config.json`, or a
 *      `packages/venia-concept/` directory exist directly under the
 *      Magento root? Cheap last-resort signal for repos that vendored
 *      Venia in-tree.
 *
 * Any single signal is sufficient — non-PWA stores get zero IC-92x
 * findings. The detection record is memoised inside the per-request DI
 * singleton so the four PWA-aware checks pay the lookup cost once.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\PwaStudio;

use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use JsonException;
use Throwable;

/**
 * Detects whether the current Magento install is paired with a PWA Studio
 * (Venia) storefront.
 *
 * Read-only — composes the existing `ComposerLockReader` and a handful
 * of cheap filesystem stats. Never opens a network socket, never reads
 * customer/order PII, never mutates state. Results are memoised inside
 * the per-request DI singleton.
 */
class PwaStudioDetector
{
    /**
     * Composer packages that, if present, indicate the merchant has
     * pulled PWA Studio scaffolding into the Magento install.
     *
     * @var list<string>
     */
    public const COMPOSER_PACKAGES = [
        'magento/module-pwa',
        'magento/pwa',
    ];

    /**
     * npm package names (in `package.json` `dependencies` or
     * `devDependencies`) that mark a co-located PWA Studio storefront.
     *
     * @var list<string>
     */
    public const NPM_PACKAGES = [
        '@magento/pwa-studio',
        '@magento/venia-ui',
        '@magento/peregrine',
        '@magento/venia-concept',
    ];

    /**
     * Filesystem markers we look for under the Magento root. Cheap
     * `is_file` / `is_dir` only.
     *
     * @var list<string>
     */
    private const FS_MARKERS = [
        'pwa-studio.config.json',
        'venia.config.json',
        'packages/venia-concept',
    ];

    /**
     * @var array{
     *     detected:bool,
     *     signals:array{composer:bool, npm:bool, filesystem:bool},
     *     composer_packages:array<string,string>,
     *     npm_packages:array<string,string>
     * }|null
     */
    private ?array $cached = null;

    public function __construct(
        private readonly ComposerLockReader $lockReader,
        private readonly ?string $magentoRoot = null
    ) {
    }

    /**
     * Return whether PWA Studio is detected. Cheap; cached.
     */
    public function isDetected(): bool
    {
        return $this->detect()['detected'];
    }

    /**
     * Full detection record — used by IC-921..IC-923 evidence payloads.
     *
     * @return array{
     *     detected:bool,
     *     signals:array{composer:bool, npm:bool, filesystem:bool},
     *     composer_packages:array<string,string>,
     *     npm_packages:array<string,string>
     * }
     */
    public function detect(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $composerMatches = $this->composerSignal();
        $npmMatches = $this->npmSignal();
        $fsSignal = $this->filesystemSignal();

        $this->cached = [
            'detected' => $composerMatches !== []
                || $npmMatches !== []
                || $fsSignal,
            'signals' => [
                'composer' => $composerMatches !== [],
                'npm' => $npmMatches !== [],
                'filesystem' => $fsSignal,
            ],
            'composer_packages' => $composerMatches,
            'npm_packages' => $npmMatches,
        ];
        return $this->cached;
    }

    /**
     * Test seam — reset memoised result. Never called in production.
     */
    public function reset(): void
    {
        $this->cached = null;
    }

    /**
     * @return array<string,string>
     */
    private function composerSignal(): array
    {
        try {
            $installed = $this->lockReader->packages();
        } catch (Throwable) {
            return [];
        }
        $matches = [];
        foreach (self::COMPOSER_PACKAGES as $name) {
            if (isset($installed[$name])) {
                $matches[$name] = $installed[$name];
            }
        }
        return $matches;
    }

    /**
     * Read `package.json` from the Magento root (if present) and pull
     * out any `@magento/*` PWA Studio dependency. Tolerates a missing
     * or malformed `package.json` — non-PWA stores legitimately have
     * no JS package manifest at the root.
     *
     * @return array<string,string>
     */
    private function npmSignal(): array
    {
        $root = $this->resolveMagentoRoot();
        if ($root === null) {
            return [];
        }
        $path = $root . DIRECTORY_SEPARATOR . 'package.json';
        if (!is_file($path)) {
            return [];
        }
        $body = @file_get_contents($path);
        if ($body === false || $body === '') {
            return [];
        }
        try {
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        $matches = [];
        foreach (['dependencies', 'devDependencies', 'peerDependencies'] as $bucket) {
            $deps = $decoded[$bucket] ?? null;
            if (!is_array($deps)) {
                continue;
            }
            foreach (self::NPM_PACKAGES as $name) {
                $version = $deps[$name] ?? null;
                if (is_string($version) && $version !== '' && !isset($matches[$name])) {
                    $matches[$name] = $version;
                }
            }
        }
        return $matches;
    }

    private function filesystemSignal(): bool
    {
        $root = $this->resolveMagentoRoot();
        if ($root === null) {
            return false;
        }
        foreach (self::FS_MARKERS as $marker) {
            $candidate = $root
                . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $marker);
            if (is_file($candidate) || is_dir($candidate)) {
                return true;
            }
        }
        return false;
    }

    private function resolveMagentoRoot(): ?string
    {
        if ($this->magentoRoot !== null) {
            return is_dir($this->magentoRoot) ? $this->magentoRoot : null;
        }
        $dir = __DIR__;
        for ($i = 0; $i < 10; $i++) {
            if (is_file($dir . DIRECTORY_SEPARATOR . 'composer.lock')) {
                return $dir;
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
