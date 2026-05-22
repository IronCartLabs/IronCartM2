<?php

/**
 * IronCart_Scan — shared manifest-entries sanitiser.
 *
 * Three manifest repositories ({@see \IronCart\Scan\Check\FileIntegrity\ManifestRepository},
 * {@see \IronCart\Scan\Check\FileIntegrity\ComposerLockManifestRepository},
 * and {@see \IronCart\Scan\Check\Integrity\BaselineRepository}) all decode a
 * JSON document of the shape `{"entries": {<relative_path>: <hex_hash>}}` and
 * need to apply the same defensive filter before handing the map to a value
 * object. Before this helper existed each repository had its own inline loop
 * (IC-070, IC-072, IC-073). The loops were byte-for-byte identical bar
 * cosmetic differences and quietly drifted when one was hardened — exactly
 * the pattern the CLAUDE.md anti-abstraction rule says to extract once a
 * third use appears.
 *
 * Rules applied (in order, an entry must satisfy all of them to be kept):
 *
 *   1. Key and value are both strings (JSON-decoded non-string keys appear
 *      as integers when a manifest author accidentally writes `"0": "…"`).
 *   2. Neither key nor value is the empty string.
 *   3. The relative-path key does NOT contain `..` (forward- or back-slash
 *      traversal) — `../etc/passwd` and `..\\etc\\passwd` are both rejected.
 *   4. The relative-path key does NOT start with `/` or `\\` — leading
 *      separators would let a malformed manifest read outside the Magento
 *      root.
 *   5. The relative-path key does NOT contain a NUL byte — defence against
 *      truncation attacks against downstream `realpath()` / `is_file()`.
 *
 * Surviving entries have their hex hash value lowercased; the relative-path
 * key is preserved verbatim (callers that need further normalisation can
 * apply it on top).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Manifests;

/**
 * Stateless sanitisation helper for manifest entry maps.
 *
 * Lives in its own namespace so cross-cutting use (FileIntegrity + Integrity)
 * doesn't create a circular dependency between those subtrees.
 */
final class ManifestEntrySanitiser
{
    /**
     * Apply the defensive filter described in the class doc.
     *
     * @param array<mixed,mixed> $rawEntries Decoded `entries` map; keys + values
     *                                       arrive as whatever JSON produced.
     * @return array<string,string> relative_path => lowercase hex hash; insertion
     *                              order preserved.
     */
    public static function sanitise(array $rawEntries): array
    {
        $sanitised = [];
        foreach ($rawEntries as $relative => $hash) {
            if (!is_string($relative) || !is_string($hash)) {
                continue;
            }
            if ($relative === '' || $hash === '') {
                continue;
            }
            // Reject traversal segments regardless of slash direction so a
            // malformed manifest cannot make a check read outside the
            // Magento root. The forward-slash check would already catch
            // POSIX-style `../`, but Windows-style `..\foo` would slip past
            // the original IC-070 loop — this hardens that case for all
            // three callers in one place.
            if (
                str_contains($relative, '..')
                || str_starts_with($relative, '/')
                || str_starts_with($relative, '\\')
                || str_contains($relative, "\0")
            ) {
                continue;
            }
            $sanitised[$relative] = strtolower($hash);
        }

        return $sanitised;
    }
}
