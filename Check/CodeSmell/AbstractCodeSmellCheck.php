<?php

/**
 * IronCart_Scan — base class for the code-smell check pack (IC-050..IC-054).
 *
 * Every code-smell check shares the same skeleton:
 *
 *   1. Walk `app/code/**\/*.php` via {@see AppCodeWalker}
 *   2. Read each file, tokenise it
 *   3. Scan tokens for one specific dangerous pattern
 *   4. Emit one finding per match with `{file, line, snippet}` evidence
 *
 * Subclasses only have to implement {@see scanTokens()} — the file walk,
 * token cache, snippet extraction, and finding envelope are owned here so
 * the per-check classes stay focused on the pattern.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\CodeSmell;

use IronCart\Scan\Check\CheckInterface;

/**
 * Shared scaffolding for the strict-pattern code-smell checks.
 */
abstract class AbstractCodeSmellCheck implements CheckInterface
{
    public function __construct(protected readonly AppCodeWalker $walker)
    {
    }

    /**
     * Stable identifier for the check (e.g. `IC-050`).
     */
    abstract public function id(): string;

    /**
     * Short human-readable headline for findings emitted by this check.
     */
    abstract public function title(): string;

    /**
     * Severity for findings emitted by this check.
     *
     * One of {@see \IronCart\Scan\Report\Severity}::ALL.
     */
    abstract public function severity(): string;

    /**
     * Remediation URL on `https://ironcart.dev/docs/checks/<id>`.
     */
    abstract public function remediationUrl(): string;

    /**
     * Find all matches of this check's pattern within a tokenised file.
     *
     * Implementations receive both the raw source (for snippet extraction)
     * and the full token list. They return a list of 1-indexed line numbers
     * where the pattern occurs; the base class fills in the rest of the
     * finding envelope.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return list<int> 1-indexed line numbers of matches
     */
    abstract protected function scanTokens(array $tokens, string $source): array;

    /**
     * @inheritDoc
     */
    public function run(): array
    {
        $findings = [];

        foreach ($this->walker->phpFiles() as $path) {
            $source = @file_get_contents($path);
            if ($source === false || $source === '') {
                continue;
            }

            $tokens = TokenScanner::tokenize($source);
            if ($tokens === []) {
                continue;
            }

            foreach ($this->scanTokens($tokens, $source) as $line) {
                $findings[] = [
                    'id' => $this->id(),
                    'title' => $this->title(),
                    'severity' => $this->severity(),
                    'evidence' => [
                        'file' => $path,
                        'line' => $line,
                        'snippet' => TokenScanner::snippet($source, $line),
                    ],
                    'remediation_url' => $this->remediationUrl(),
                ];
            }
        }

        return $findings;
    }
}
