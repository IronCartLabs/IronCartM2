<?php

/**
 * IronCart_Scan — deprecation taxonomy registry.
 *
 * Single source of truth for the v5 announce-before-remove migration plan
 * (issue #83). Maps check ids that will move out of the OSS package in
 * v2.0.0 to their deprecation metadata: when the deprecation landed,
 * when the removal will happen, the replacement package, and the public
 * migration URL.
 *
 * Read by:
 *   - {@see \IronCart\Scan\Check\CheckRegistry} — filters the
 *     to-execute list when `--include-deprecated` is false.
 *   - {@see \IronCart\Scan\Console\Command\ScanCommand} — emits the
 *     one-line stderr deprecation notice for every deprecated check that
 *     ran during this invocation.
 *   - {@see \IronCart\Scan\Report\ReportBuilder} — decorates each
 *     finding whose `id` is in the map with the optional
 *     `deprecated_in` / `removal_in` / `replacement` /
 *     `migration_url` fields (v1 additive schema bump).
 *   - {@see \IronCart\Scan\Ui\Component\Listing\Column\SeverityBadge}
 *     and the admin scan-run UI — renders a `[deprecated]` badge on
 *     finding rows whose check id is in the map.
 *
 * **Per-id semantics.** A check id appears in this registry exactly when
 * the OSS package will stop emitting it in v2.0.0. v1.x behaviour is
 * unchanged — deprecated checks still run by default. The flip to
 * default-false happens in a separate v2.0.0 ticket, not here.
 *
 * **Additive only.** Removing or relaxing an entry here is a breaking
 * change for downstream automation that consumes the JSON `deprecated_in`
 * field — bump the report `SCHEMA_VERSION` and call it out in the PR.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check;

/**
 * Registry of deprecated check ids and their migration metadata.
 *
 * Stateless and singleton-safe — every method is pure of the constant
 * data table below. DI-injected as a regular service so tests can swap
 * an alternative-data subclass for fixture work.
 */
class DeprecationRegistry
{
    /**
     * Module version at which the v5 announce-before-remove plan started
     * emitting deprecation notices. Pinned to a constant so the test
     * suite can assert the contract without hard-coding the same string
     * in two places.
     */
    public const DEPRECATED_IN = '1.3.0';

    /**
     * Module version at which the deprecated checks will be removed
     * from the OSS package. The v2.0.0 release is the only place this
     * version bump may land — flip the {@see ScanSession::includeDeprecated()}
     * default and remove the deprecated check classes in a single PR.
     */
    public const REMOVAL_IN = '2.0.0';

    /**
     * Composer package the deprecated checks move into in v2.0.0.
     */
    public const REPLACEMENT_PACKAGE = 'ironcartlabs/magento-scan-pro';

    /**
     * Public migration doc on ironcart.dev. The page is filed under a
     * separate `agent:content` follow-up — when the deprecation lands
     * the URL is reserved but the page may render a stub. That is
     * acceptable per the issue body; the URL must not 404 because the
     * notice points operators at it.
     */
    public const MIGRATION_URL = 'https://ironcart.dev/docs/scanner/migration-v5/';

    /**
     * Map of check id → deprecation metadata. **Companion / fallback ids**
     * emitted by the same check class as the primary id are listed here too
     * so a transport-failure IC-061 row (emitted by the IC-060 check) also
     * carries the badge, and missing-manifest IC-071 / IC-073 (emitted by
     * the IC-070 / IC-072 checks) carry it too.
     *
     * Each entry is a frozen shape — no per-check overrides today, but the
     * shape is preserved for the v6 + cycle when a check might move into
     * the pro tier on a different schedule than its siblings.
     *
     * @var array<string, array{
     *     deprecated_in:string,
     *     removal_in:string,
     *     replacement:string,
     *     migration_url:string
     * }>
     */
    private const DEPRECATED_CHECKS = [
        // IC-060 — OSV.dev CVE cross-reference (proxy client).
        'IC-060' => [
            'deprecated_in' => self::DEPRECATED_IN,
            'removal_in' => self::REMOVAL_IN,
            'replacement' => self::REPLACEMENT_PACKAGE,
            'migration_url' => self::MIGRATION_URL,
        ],
        // IC-061 — IC-060 transport-failure fallback. Same class.
        'IC-061' => [
            'deprecated_in' => self::DEPRECATED_IN,
            'removal_in' => self::REMOVAL_IN,
            'replacement' => self::REPLACEMENT_PACKAGE,
            'migration_url' => self::MIGRATION_URL,
        ],
        // IC-070 — core file SHA-256 integrity vs bundled manifest.
        'IC-070' => [
            'deprecated_in' => self::DEPRECATED_IN,
            'removal_in' => self::REMOVAL_IN,
            'replacement' => self::REPLACEMENT_PACKAGE,
            'migration_url' => self::MIGRATION_URL,
        ],
        // IC-071 — IC-070 unsupported-manifest fallback. Same class.
        'IC-071' => [
            'deprecated_in' => self::DEPRECATED_IN,
            'removal_in' => self::REMOVAL_IN,
            'replacement' => self::REPLACEMENT_PACKAGE,
            'migration_url' => self::MIGRATION_URL,
        ],
        // IC-072 — composer.lock dist.shasum vs reference manifest.
        'IC-072' => [
            'deprecated_in' => self::DEPRECATED_IN,
            'removal_in' => self::REMOVAL_IN,
            'replacement' => self::REPLACEMENT_PACKAGE,
            'migration_url' => self::MIGRATION_URL,
        ],
        // IC-073 — IC-072 unsupported-manifest fallback. Same class.
        'IC-073' => [
            'deprecated_in' => self::DEPRECATED_IN,
            'removal_in' => self::REMOVAL_IN,
            'replacement' => self::REPLACEMENT_PACKAGE,
            'migration_url' => self::MIGRATION_URL,
        ],
    ];

    /**
     * Primary check-registry keys (the names used in `etc/di.xml`). Used
     * by {@see CheckRegistry::runAll()} to skip the run entirely when
     * `--include-deprecated` is false — distinct from
     * {@see self::isDeprecated()} which also matches the fallback ids a
     * check class emits internally (IC-061, IC-071, IC-073).
     *
     * @var list<string>
     */
    private const DEPRECATED_REGISTRY_KEYS = [
        'IC-060',
        'IC-070',
        'IC-072',
    ];

    /**
     * True when the given finding/check id will be removed in v2.0.0.
     */
    public function isDeprecated(string $id): bool
    {
        return array_key_exists($id, self::DEPRECATED_CHECKS);
    }

    /**
     * True when the given key registered in `etc/di.xml` for
     * {@see CheckRegistry} is the entry point of a deprecated check.
     * Used by the registry's filter pass; distinct from
     * {@see self::isDeprecated()} because the registry only ever sees
     * the primary id (IC-060 / IC-070 / IC-072), never the
     * companion fallback ids the same class may emit.
     */
    public function isDeprecatedRegistryKey(string $registryKey): bool
    {
        return in_array($registryKey, self::DEPRECATED_REGISTRY_KEYS, true);
    }

    /**
     * Return the deprecation metadata for the given id, or null if the
     * id is not deprecated. The returned array shape is stable — any
     * future field additions must be optional or bumped via the report
     * schema version.
     *
     * @return array{
     *     deprecated_in:string,
     *     removal_in:string,
     *     replacement:string,
     *     migration_url:string
     * }|null
     */
    public function metadataFor(string $id): ?array
    {
        return self::DEPRECATED_CHECKS[$id] ?? null;
    }

    /**
     * Return the primary check-registry keys (di.xml entries) that are
     * deprecated. Stable order — used by the stderr notice emitter so
     * operators see the same sequence on every run.
     *
     * @return list<string>
     */
    public function deprecatedRegistryKeys(): array
    {
        return self::DEPRECATED_REGISTRY_KEYS;
    }

    /**
     * One-line stderr notice copy. Centralised here so the unit tests
     * can pin the exact string and the admin UI tooltip can reuse it.
     */
    public function notice(string $registryKey): string
    {
        $meta = $this->metadataFor($registryKey);
        if ($meta === null) {
            // Caller bug — only invoked with a deprecated key.
            return sprintf('[DEPRECATED] %s', $registryKey);
        }
        return sprintf(
            '[DEPRECATED] %s will move to %s in v%s. See %s '
            . '— pass flag include-deprecated=false to silence this notice.',
            $registryKey,
            $meta['replacement'],
            $meta['removal_in'],
            $meta['migration_url']
        );
    }
}
