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
     * @param array<string,CheckInterface> $checks  keyed by check id
     */
    public function __construct(private readonly array $checks = [])
    {
    }

    /**
     * Run every registered check and return the flat list of findings.
     *
     * @return list<array{
     *     id:string,
     *     title:string,
     *     severity:string,
     *     evidence:mixed,
     *     remediation_url:string
     * }>
     */
    public function runAll(): array
    {
        $findings = [];
        foreach ($this->checks as $check) {
            foreach ($check->run() as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * @return array<string,CheckInterface>
     */
    public function all(): array
    {
        return $this->checks;
    }
}
