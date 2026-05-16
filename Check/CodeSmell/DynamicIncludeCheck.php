<?php

/**
 * IronCart_Scan — IC-052: dynamic include / require.
 *
 * `include $var`, `require $var`, etc. — where the path is a variable
 * instead of a string literal — is a local-file-inclusion / remote-file-
 * inclusion gadget. Even when the variable is internally-sourced today,
 * it's a refactoring hazard: one observer or plugin that lets user input
 * influence the value turns it into an RCE.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\CodeSmell;

use IronCart\Scan\Report\Severity;

/**
 * IC-052 — flag `include`/`require` (and their `*_once` variants) when
 * the argument is a variable rather than a string literal.
 */
class DynamicIncludeCheck extends AbstractCodeSmellCheck
{
    public const ID = 'IC-052';

    /**
     * Token ids that represent the include / require language constructs.
     *
     * @var list<int>
     */
    private const INCLUDE_TOKENS = [
        T_INCLUDE,
        T_INCLUDE_ONCE,
        T_REQUIRE,
        T_REQUIRE_ONCE,
    ];

    public function id(): string
    {
        return self::ID;
    }

    public function title(): string
    {
        return 'Dynamic include — LFI / RFI vector';
    }

    public function severity(): string
    {
        return Severity::HIGH;
    }

    public function remediationUrl(): string
    {
        return 'https://ironcart.dev/docs/checks/IC-052';
    }

    /**
     * @inheritDoc
     */
    protected function scanTokens(array $tokens, string $source): array
    {
        $matches = [];

        foreach ($tokens as $i => $token) {
            if (!is_array($token) || !in_array($token[0], self::INCLUDE_TOKENS, true)) {
                continue;
            }

            // Walk past optional `(`. PHP permits both
            //   include $foo;
            //   include($foo);
            // and the tokeniser doesn't fold the parens away.
            $cursor = TokenScanner::nextNonTrivia($tokens, $i + 1);
            if ($cursor === null) {
                continue;
            }
            if ($tokens[$cursor] === '(') {
                $cursor = TokenScanner::nextNonTrivia($tokens, $cursor + 1);
                if ($cursor === null) {
                    continue;
                }
            }

            // String literal first argument → static include, safe.
            // Variable / interpolated form → dynamic include, flag.
            $argToken = $tokens[$cursor];
            if (!is_array($argToken)) {
                continue;
            }

            if ($argToken[0] === T_VARIABLE) {
                $matches[] = $token[2];
            }
        }

        return $matches;
    }
}
