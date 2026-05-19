<?php

/**
 * IronCart_Scan — Recon 7.1 ignore-pattern matcher.
 *
 * Loads the bundled `etc/integrity-ignore.json` whitelist of webroot-relative
 * paths that are known-mutable (caches, generated, var/log, pub/static,
 * pub/media, ...) and exposes a single {@see matches()} predicate the
 * baseline builder + diff use to skip them.
 *
 * The whitelist is a JSON file shipped with the module rather than a hard-
 * coded constant so the rule set is reviewable in PR rather than embedded
 * in PHP — but the file itself is bundled with the module and Magento
 * Composer-installs it read-only. DI-friendly: the constructor takes an
 * optional config-file path (defaults to the bundled location), and falls
 * back to safe built-in defaults when the file is unavailable so a partial
 * deployment still skips obviously-mutable paths.
 *
 *     {
 *       "schema_version": "v0",
 *       "prefixes": ["var/", "generated/", "pub/static/", "pub/media/"],
 *       "exact":    ["app/etc/env.php", "app/etc/config.php"]
 *     }
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Integrity;

use RuntimeException;

/**
 * Path-prefix / exact-match whitelist for known-mutable webroot paths.
 */
class IgnorePatterns
{
    public const SCHEMA_VERSION = 'v0';

    /**
     * @var list<string>
     */
    private array $prefixes;

    /**
     * @var list<string>
     */
    private array $exact;

    /**
     * @param string|null $configPath Absolute path to the JSON whitelist.
     *                                Defaults to the bundled
     *                                `<module-root>/etc/integrity-ignore.json`.
     *                                Pass an explicit path in tests to
     *                                exercise overrides. When the file
     *                                cannot be read, defaults are used.
     */
    public function __construct(?string $configPath = null)
    {
        $path = $configPath ?? dirname(__DIR__, 2) . '/etc/integrity-ignore.json';
        $loaded = self::tryLoad($path);
        if ($loaded === null) {
            $loaded = self::defaultLists();
        }
        [$this->prefixes, $this->exact] = $loaded;
    }

    /**
     * Construct directly from in-memory lists. Primarily for tests.
     *
     * @param list<string> $prefixes
     * @param list<string> $exact
     */
    public static function fromLists(array $prefixes, array $exact): self
    {
        $instance = new self(__FILE__); // any path; we overwrite below
        $instance->prefixes = self::sanitiseList($prefixes);
        $instance->exact = self::sanitiseList($exact);
        return $instance;
    }

    public function matches(string $relativePath): bool
    {
        if ($relativePath === '') {
            return true;
        }
        if (in_array($relativePath, $this->exact, true)) {
            return true;
        }
        foreach ($this->prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($relativePath, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function prefixes(): array
    {
        return $this->prefixes;
    }

    /**
     * @return list<string>
     */
    public function exact(): array
    {
        return $this->exact;
    }

    /**
     * @return array{0:list<string>,1:list<string>}|null
     */
    private static function tryLoad(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException(sprintf('Integrity ignore file %s is not valid JSON', basename($path)));
        }
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Integrity ignore file %s did not decode to an object', basename($path)));
        }
        $schema = $decoded['schema_version'] ?? null;
        if ($schema !== self::SCHEMA_VERSION) {
            throw new RuntimeException(sprintf(
                'Integrity ignore file %s has schema_version=%s; expected %s',
                basename($path),
                is_scalar($schema) ? (string) $schema : 'null',
                self::SCHEMA_VERSION
            ));
        }

        return [
            self::sanitiseList($decoded['prefixes'] ?? []),
            self::sanitiseList($decoded['exact'] ?? []),
        ];
    }

    /**
     * @return array{0:list<string>,1:list<string>}
     */
    private static function defaultLists(): array
    {
        return [
            ['var/', 'generated/', 'pub/static/', 'pub/media/', '.git/'],
            ['app/etc/env.php', 'app/etc/config.php'],
        ];
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function sanitiseList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                continue;
            }
            if (str_contains($item, '..') || str_starts_with($item, '/') || str_contains($item, "\0")) {
                continue;
            }
            $out[] = $item;
        }

        return array_values(array_unique($out));
    }
}
