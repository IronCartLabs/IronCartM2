<?php

/**
 * IronCart_Scan — check registry.
 *
 * Aggregates every registered {@see CheckInterface} into a single iterator
 * the scan command can drive. Checks are injected via Magento DI in
 * `etc/di.xml` as an array argument keyed by check id — additive entries
 * only so sibling check-pack PRs (#3, #4, #5, #7) can land without
 * conflicting.
 *
 * **Deprecation filter (issue #83).** When the operator passes
 * `--include-deprecated=false` on the CLI, the registry consults the
 * {@see DeprecationRegistry} and skips check entries whose registry key is
 * in the deprecated set. In v1.x the default is `true` (run everything),
 * so the existing behaviour is unchanged. The v2.0.0 ticket flips the
 * default — not this PR.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check;

/**
 * Registry of scanner checks, injected by Magento DI.
 */
class CheckRegistry
{
    /**
     * Registry keys (di.xml entries) of deprecated checks that were
     * actually executed during the most recent {@see self::runAll()}
     * call. Populated even when the suppression flag is on so the CLI
     * can decide whether to emit a stderr notice.
     *
     * @var list<string>
     */
    private array $lastRunDeprecatedKeys = [];

    /**
     * @param array<string,CheckInterface> $checks       keyed by check id
     * @param DeprecationRegistry|null     $deprecations Optional — when null
     *     the registry behaves identically to v0: every check runs,
     *     deprecation tracking is a no-op. Always wired in production via
     *     `etc/di.xml`; nullable so unit tests can keep their existing
     *     fixtures shape-compatible.
     */
    public function __construct(
        private readonly array $checks = [],
        private readonly ?DeprecationRegistry $deprecations = null
    ) {
    }

    /**
     * Run every registered check and return the flat list of findings.
     *
     * When `$includeDeprecated` is false and a {@see DeprecationRegistry}
     * was wired, checks whose registry key is in the deprecated set are
     * skipped entirely — they never instantiate their domain dependencies
     * and never emit findings. When `true` (the v1.x default), all
     * registered checks run as before.
     *
     * @return list<array{
     *     id:string,
     *     title:string,
     *     severity:string,
     *     evidence:mixed,
     *     remediation_url:string
     * }>
     */
    public function runAll(bool $includeDeprecated = true): array
    {
        $this->lastRunDeprecatedKeys = [];
        $findings = [];
        foreach ($this->checks as $registryKey => $check) {
            $registryKey = (string)$registryKey;
            $isDeprecated = $this->deprecations !== null
                && $this->deprecations->isDeprecatedRegistryKey($registryKey);

            if ($isDeprecated && !$includeDeprecated) {
                // Skipped per `--include-deprecated=false`; do NOT record
                // it as having run — the stderr notice is for things that
                // actually executed.
                continue;
            }
            if ($isDeprecated) {
                $this->lastRunDeprecatedKeys[] = $registryKey;
            }
            foreach ($check->run() as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Registry keys of deprecated checks that ran during the most recent
     * {@see self::runAll()} call. Used by {@see \IronCart\Scan\Console\Command\ScanCommand}
     * to emit one stderr deprecation notice per ran deprecated check.
     *
     * Order matches the underlying check map iteration order so operators
     * see a stable sequence on every run.
     *
     * @return list<string>
     */
    public function lastRunDeprecatedKeys(): array
    {
        return $this->lastRunDeprecatedKeys;
    }

    /**
     * @return array<string,CheckInterface>
     */
    public function all(): array
    {
        return $this->checks;
    }
}
