<?php

/**
 * IronCart_Scan — OSV snapshot loader.
 *
 * Loads the bundled `data/osv-magento.json` file and exposes it as a
 * package-keyed map. The snapshot is a stripped-down view of the
 * OSV.dev Magento ecosystem and must remain ≤ 500 KB on disk (issue #3
 * constraint). Refresh procedure is documented in `data/README.md`.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\PatchLevel;

use JsonException;
use RuntimeException;

/**
 * Read-only loader for the bundled OSV advisory snapshot.
 *
 * Expected JSON shape:
 *
 * ```json
 * {
 *   "schema_version": "v0",
 *   "generated_at": "YYYY-MM-DD",
 *   "source": "https://osv.dev/",
 *   "advisories": [
 *     {
 *       "id": "GHSA-xxxx-xxxx-xxxx",
 *       "aliases": ["CVE-2024-...","..."],
 *       "summary": "...",
 *       "published": "YYYY-MM-DD",
 *       "severity": "critical|high|medium|low",
 *       "package": "vendor/name",
 *       "affected": [
 *         {"introduced": "0"},
 *         {"fixed": "1.2.3"}
 *       ],
 *       "reference": "https://..."
 *     }
 *   ]
 * }
 * ```
 */
class OsvSnapshotLoader
{
    /**
     * @param string|null $snapshotPath Override the bundled snapshot
     *                                  location. Tests rely on this.
     */
    public function __construct(private readonly ?string $snapshotPath = null)
    {
    }

    /**
     * Return advisories grouped by package name.
     *
     * @return array<string,list<array{
     *     id:string,
     *     aliases:list<string>,
     *     summary:string,
     *     published:string,
     *     severity:string,
     *     package:string,
     *     affected:list<array<string,string>>,
     *     reference:string
     * }>>
     *
     * @throws RuntimeException When the snapshot is missing or malformed.
     */
    public function advisoriesByPackage(): array
    {
        $path = $this->snapshotPath ?? self::defaultPath();
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('OSV snapshot not found at "%s".', $path));
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read OSV snapshot "%s".', $path));
        }

        try {
            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Invalid OSV snapshot JSON: %s', $e->getMessage()), 0, $e);
        }

        if (!is_array($decoded) || !isset($decoded['advisories']) || !is_array($decoded['advisories'])) {
            throw new RuntimeException('OSV snapshot is missing the "advisories" array.');
        }

        $grouped = [];
        foreach ($decoded['advisories'] as $advisory) {
            if (!is_array($advisory)) {
                continue;
            }
            $package = $advisory['package'] ?? null;
            if (!is_string($package) || $package === '') {
                continue;
            }
            $grouped[$package][] = self::normaliseAdvisory($advisory);
        }

        return $grouped;
    }

    /**
     * Default snapshot location: `<module-root>/data/osv-magento.json`.
     */
    public static function defaultPath(): string
    {
        // ComposerLockReader / this class live at <root>/Check/PatchLevel/,
        // so the snapshot is two directories up.
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'osv-magento.json';
    }

    /**
     * Coerce raw advisory entries to the documented shape, filling in
     * sensible defaults so consumers don't have to defensively check
     * every field.
     *
     * @param array<string,mixed> $raw
     *
     * @return array{
     *     id:string,
     *     aliases:list<string>,
     *     summary:string,
     *     published:string,
     *     severity:string,
     *     package:string,
     *     affected:list<array<string,string>>,
     *     reference:string
     * }
     */
    private static function normaliseAdvisory(array $raw): array
    {
        $aliases = [];
        if (isset($raw['aliases']) && is_array($raw['aliases'])) {
            foreach ($raw['aliases'] as $alias) {
                if (is_string($alias) && $alias !== '') {
                    $aliases[] = $alias;
                }
            }
        }

        $affected = [];
        if (isset($raw['affected']) && is_array($raw['affected'])) {
            foreach ($raw['affected'] as $range) {
                if (!is_array($range)) {
                    continue;
                }
                $entry = [];
                foreach (['introduced', 'fixed', 'last_affected'] as $key) {
                    if (isset($range[$key]) && is_string($range[$key])) {
                        $entry[$key] = $range[$key];
                    }
                }
                if ($entry !== []) {
                    $affected[] = $entry;
                }
            }
        }

        return [
            'id' => (string) ($raw['id'] ?? ''),
            'aliases' => $aliases,
            'summary' => (string) ($raw['summary'] ?? ''),
            'published' => (string) ($raw['published'] ?? ''),
            'severity' => (string) ($raw['severity'] ?? 'medium'),
            'package' => (string) ($raw['package'] ?? ''),
            'affected' => $affected,
            'reference' => (string) ($raw['reference'] ?? ''),
        ];
    }
}
