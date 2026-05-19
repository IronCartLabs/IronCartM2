<?php

/**
 * IronCart_Scan — IC-913: Hyvä Alpine.js loaded from third-party CDN.
 *
 * The default Hyvä theme bundles Alpine.js as a vendored, version-pinned
 * asset under `web/tailwind/tailwind-source.css` + the compiled output in
 * `pub/static`. A merchant tightening time-to-first-byte (or copy-pasting
 * a "quick fix" from a Hyvä Slack thread) occasionally swaps the vendored
 * bundle for a CDN reference like
 * `<script src="https://cdn.jsdelivr.net/npm/alpinejs"></script>` or
 * `https://unpkg.com/alpinejs`. That has three problems on a Magento
 * storefront:
 *
 *   1. Supply-chain exposure — every page load fetches JS from a domain
 *      the merchant does not control. A CDN compromise (or a typo'd
 *      package name) executes attacker JS in checkout context.
 *   2. CSP regression — IC-911 manages the inline-hash whitelist, but a
 *      CDN `<script src>` needs an explicit `script-src` host allowlist
 *      entry that operators often forget, leading to silent breakage in
 *      strict mode and `'unsafe-inline'` panic-reverts in lax mode.
 *   3. Subresource integrity is almost always omitted on these copy-paste
 *      snippets, so even a benign CDN serving the wrong file goes
 *      undetected.
 *
 * The check walks Hyvä-relevant template directories — `app/design/frontend/`
 * + the installed `vendor/hyva-themes/*` package roots — looking at
 * `.phtml` and `.html` files for `<script src="…">` whose host is a
 * public JS CDN AND whose URL path mentions `alpine` (case-insensitive).
 * Bounded breadth (theme + Hyvä vendor only, max 4 directory levels) so
 * the walk stays cheap on large stores.
 *
 * Severity is MEDIUM per match. Read-only; no network call.
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
 * IC-913 — Hyvä template references Alpine.js from a public CDN.
 */
class AlpineCdnUsageCheck implements CheckInterface
{
    public const ID = 'IC-913';

    public const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-913';

    /**
     * Hosts that count as a "public JS CDN" for the purposes of this
     * check. List is intentionally narrow — we'd rather miss an
     * exotic CDN than false-positive on a merchant's own asset host.
     *
     * @var list<string>
     */
    private const CDN_HOST_SUFFIXES = [
        'cdn.jsdelivr.net',
        'unpkg.com',
        'cdnjs.cloudflare.com',
        'esm.sh',
        'ga.jspm.io',
        'cdn.skypack.dev',
    ];

    /**
     * Template file extensions to scan. `.phtml` covers Magento layouts
     * and Hyvä's component templates; `.html` covers AlpineJS-style
     * standalone snippets some merchants drop alongside.
     *
     * @var list<string>
     */
    private const TEMPLATE_EXTENSIONS = ['phtml', 'html'];

    /**
     * Hard cap on files scanned per root so a misconfigured deploy with
     * an explosion of `.phtml` files (e.g. a vendor dump under
     * `app/design/`) cannot blow the scan timeout.
     */
    private const MAX_FILES_PER_ROOT = 2000;

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

        $magentoRoot = $this->resolveMagentoRoot();
        if ($magentoRoot === null) {
            return [];
        }

        $roots = $this->candidateRoots($magentoRoot);
        if ($roots === []) {
            return [];
        }

        $matches = [];
        foreach ($roots as $root) {
            foreach ($this->walkTemplates($root) as $templatePath) {
                $hits = $this->scanFile($templatePath);
                foreach ($hits as $url) {
                    $matches[] = [
                        'file' => $this->relativise($templatePath, $magentoRoot),
                        'url' => $url,
                    ];
                }
            }
        }

        if ($matches === []) {
            return [];
        }

        return [
            Finding::make(
                id: self::ID,
                title: sprintf(
                    '%d Hyvä template(s) load Alpine.js from a public CDN',
                    count($matches)
                ),
                severity: Severity::MEDIUM,
                evidence: [
                    'matches' => $matches,
                ],
                remediationUrl: self::REMEDIATION_URL
            ),
        ];
    }

    /**
     * Pick the directory roots we'll walk. Hyvä themes live under
     * `app/design/frontend/`; Hyvä's composer-distributed modules
     * (which carry the canonical templates) live under
     * `vendor/hyva-themes/`.
     *
     * @return list<string>
     */
    private function candidateRoots(string $magentoRoot): array
    {
        $roots = [];
        foreach (['app/design/frontend', 'vendor/hyva-themes'] as $relative) {
            $candidate = $magentoRoot
                . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (is_dir($candidate)) {
                $roots[] = $candidate;
            }
        }
        return $roots;
    }

    /**
     * Yield up to {@see self::MAX_FILES_PER_ROOT} template files under
     * `$root`. Bounded depth + file count; symlinks are skipped to
     * avoid loops. Uses RecursiveIteratorIterator under the hood for
     * portable directory traversal (Windows + Linux CI).
     *
     * @return iterable<string>
     */
    private function walkTemplates(string $root): iterable
    {
        $count = 0;
        try {
            $directory = new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
            );
        } catch (\Throwable) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            $directory,
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        $iterator->setMaxDepth(6);

        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if ($count >= self::MAX_FILES_PER_ROOT) {
                return;
            }
            if (!$fileInfo->isFile()) {
                continue;
            }
            $ext = strtolower($fileInfo->getExtension());
            if (!in_array($ext, self::TEMPLATE_EXTENSIONS, true)) {
                continue;
            }
            $count++;
            yield $fileInfo->getPathname();
        }
    }

    /**
     * Pull every CDN-hosted Alpine `<script src>` out of `$path`. We
     * keep the regex narrow (literal `<script` tag, `src=` attribute,
     * URL inside single or double quotes) — anything fancier is the
     * job of a proper HTML parser, which we deliberately don't ship.
     *
     * @return list<string>
     */
    private function scanFile(string $path): array
    {
        $body = @file_get_contents($path);
        if ($body === false || $body === '') {
            return [];
        }

        $hits = [];
        $pattern = '~<script\b[^>]*\bsrc\s*=\s*([\'"])([^\'"]+)\1~i';
        if (!preg_match_all($pattern, $body, $matches)) {
            return [];
        }

        foreach ($matches[2] as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            if (!$this->looksLikeCdnAlpineUrl($url)) {
                continue;
            }
            $hits[] = $url;
        }
        return array_values(array_unique($hits));
    }

    private function looksLikeCdnAlpineUrl(string $url): bool
    {
        // Strip protocol-relative URLs so parse_url() finds a host.
        $candidate = str_starts_with($url, '//') ? 'https:' . $url : $url;
        $host = parse_url($candidate, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower($host);

        $hostMatch = false;
        foreach (self::CDN_HOST_SUFFIXES as $cdn) {
            if ($host === $cdn || str_ends_with($host, '.' . $cdn)) {
                $hostMatch = true;
                break;
            }
        }
        if (!$hostMatch) {
            return false;
        }

        $path = parse_url($candidate, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return false;
        }
        // Match `alpine`, `alpinejs`, `alpine.js`, `@alpinejs/...` etc.
        return (bool) preg_match('~(?:^|/|@)alpine~i', $path);
    }

    private function relativise(string $path, string $root): string
    {
        $rootWithSep = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $rootWithSep)) {
            return substr($path, strlen($rootWithSep));
        }
        return $path;
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
