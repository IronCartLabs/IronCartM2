<?php

/**
 * IronCart_Scan — IC-054: `preg_replace` with the `/e` modifier.
 *
 * The `/e` PCRE modifier evaluates its replacement as PHP. It was
 * deprecated in PHP 5.5 and removed in PHP 7.0, but a non-trivial number
 * of Magento 1 → Magento 2 ports and old marketplace modules still
 * contain the literal pattern. On a PHP 5.x backport the call is an
 * unauthenticated RCE; on PHP 7+ the call is a hard error at runtime,
 * which means the surrounding code path has never been exercised and
 * deserves attention regardless.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\CodeSmell;

use IronCart\Scan\Report\Severity;

/**
 * IC-054 — flag `preg_replace(/.../e, ...)` calls.
 */
class PregReplaceEvalModifierCheck extends AbstractCodeSmellCheck
{
    public const ID = 'IC-054';

    public function id(): string
    {
        return self::ID;
    }

    public function title(): string
    {
        return 'preg_replace with /e modifier — RCE vector';
    }

    public function severity(): string
    {
        return Severity::CRITICAL;
    }

    public function remediationUrl(): string
    {
        return 'https://ironcart.dev/docs/checks/IC-054';
    }

    /**
     * @inheritDoc
     */
    protected function scanTokens(array $tokens, string $source): array
    {
        $matches = [];
        $tokenCount = count($tokens);

        foreach ($tokens as $i => $token) {
            if (!TokenScanner::isFunctionCall($tokens, $i, ['preg_replace'])) {
                continue;
            }

            // Step past `preg_replace` and `(`. The next non-trivia
            // token is the first character of the first argument — the
            // pattern. The pattern may be a `T_CONSTANT_ENCAPSED_STRING`
            // literal (most common), an array of patterns (also a
            // literal, comma-separated), a `T_VARIABLE` (we can't
            // analyse the value statically — skip), or a more complex
            // expression. We only flag literal patterns whose delimited
            // form ends in `e` (with optional sibling flags).
            $paren = TokenScanner::nextNonTrivia($tokens, $i + 1);
            if ($paren === null || $tokens[$paren] !== '(') {
                continue;
            }

            $cursor = $paren + 1;
            $depth = 1;
            while ($cursor < $tokenCount && $depth > 0) {
                $current = $tokens[$cursor];

                if ($current === '(') {
                    $depth++;
                    $cursor++;
                    continue;
                }
                if ($current === ')') {
                    $depth--;
                    $cursor++;
                    continue;
                }

                // Only inspect tokens at the call's top level (depth 1).
                if ($depth === 1 && is_array($current) && $current[0] === T_CONSTANT_ENCAPSED_STRING) {
                    if ($this->literalHasEModifier($current[1])) {
                        // isFunctionCall() above guarantees $token is
                        // the T_STRING array form; line at index 2.
                        /** @var array{0:int,1:string,2:int} $token */
                        $matches[] = $token[2];
                        // One finding per call site regardless of how
                        // many array-elements carry /e — the security
                        // reviewer only needs to land in the right file
                        // once.
                        break;
                    }
                }

                $cursor++;
            }
        }

        return $matches;
    }

    /**
     * Inspect a `T_CONSTANT_ENCAPSED_STRING` literal (with its surrounding
     * PHP quotes still attached) for a PCRE `e` modifier.
     *
     * Accepts the canonical delimiter set commonly used in real-world
     * Magento modules: `/`, `#`, `~`, `!`, `@`, `|`, plus matched-pair
     * forms `(...)`, `{...}`, `[...]`, `<...>`. Trailing modifier
     * characters after the closing delimiter may include `e` alongside
     * any of `i`, `m`, `s`, `u`, `x`, `A`, `D`, `S`, `U`, `X`, `J`.
     */
    private function literalHasEModifier(string $literal): bool
    {
        // Strip the outer PHP quotes so we can match the PCRE
        // delimiter cleanly. The literal arrives as either
        //   "'/foo/e'" or '"/foo/e"' (with PHP quotes preserved).
        if (strlen($literal) < 4) {
            return false;
        }

        $first = $literal[0];
        $last = $literal[strlen($literal) - 1];
        if ($first !== $last || ($first !== '"' && $first !== "'")) {
            return false;
        }

        $inner = substr($literal, 1, -1);

        // Trailing modifiers come after the closing delimiter. We try
        // each delimiter family. The pattern below captures any closing
        // delimiter (the literal opening delimiter, or its bracket
        // counterpart for paired forms) followed by zero or more PCRE
        // modifier flags that include at least one `e`.
        //
        // Two-pass: first the symmetrical delimiters (/.../, #...#,
        // etc.), then the bracket-pair forms (/.../e, but with one of
        // (), {}, [], <>).
        if ($inner === '') {
            return false;
        }

        $openDelim = $inner[0];
        $bracketCounterparts = ['(' => ')', '{' => '}', '[' => ']', '<' => '>'];
        $closeDelim = $bracketCounterparts[$openDelim] ?? $openDelim;

        // Find the last occurrence of the closing delimiter — PCRE
        // patterns can embed the delimiter via backslash-escape; the
        // *closing* delimiter is the last unescaped one, which is also
        // (for our purposes) just the last one full stop, because any
        // escaped delimiter still ends with `\<delim>` not bare
        // `<delim>`.
        $closeIndex = strrpos($inner, $closeDelim);
        if ($closeIndex === false || $closeIndex === 0) {
            // No closing delim, or the only delim is the opening — not
            // a valid PCRE pattern; pass.
            return false;
        }

        $modifiers = substr($inner, $closeIndex + 1);

        // Require at least one `e` somewhere in the modifier suffix,
        // and require every character in the suffix to be a valid PCRE
        // modifier letter. The second guard avoids matching things like
        // `/path/example.php` accidentally — `xample.php` is not a
        // valid modifier run.
        if ($modifiers === '' || !str_contains($modifiers, 'e')) {
            return false;
        }

        return (bool) preg_match('/\A[eimsuxADSUXJ]+\z/', $modifiers);
    }
}
