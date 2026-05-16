<?php

/**
 * IronCart_Scan — shared `token_get_all()` helpers for the code-smell pack.
 *
 * Each individual code-smell check expresses its pattern in terms of PHP
 * token sequences rather than raw regex over source text. Regex would
 * generate false positives on the pattern appearing inside a string
 * literal, a comment, or a doc-block; the PHP lexer eliminates that class
 * of error trivially because `'eval'` lexes as
 * `T_CONSTANT_ENCAPSED_STRING`, not `T_EVAL`.
 *
 * This helper centralises the boilerplate every check would otherwise
 * duplicate: tolerant tokenisation, look-ahead past whitespace and
 * comments, "is this a function-call form" disambiguation against method
 * / static / definition contexts, and snippet extraction for the
 * `evidence` field.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\CodeSmell;

/**
 * Stateless helpers for token-based code-smell detection.
 *
 * The class is `final` and has only static methods — instantiation would
 * imply state the helpers don't have.
 */
final class TokenScanner
{
    /**
     * Token ids that should be transparently skipped during look-ahead /
     * look-behind. Whitespace and comments never carry semantic meaning
     * for the patterns we care about.
     *
     * @var list<int>
     */
    private const TRIVIA_TOKENS = [
        T_WHITESPACE,
        T_COMMENT,
        T_DOC_COMMENT,
    ];

    /**
     * Tokens that, when they appear immediately before a bareword
     * identifier, mean we're NOT looking at a function-call: object
     * method, static method, nullsafe method, function definition, or a
     * class-member context.
     *
     * @var list<int>
     */
    private const NON_CALL_PRECEDERS = [
        T_OBJECT_OPERATOR,
        T_DOUBLE_COLON,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_FUNCTION,
        T_CONST,
        T_NEW,
        T_AS,
        T_USE,
        T_NAMESPACE,
    ];

    private function __construct()
    {
    }

    /**
     * Tokenise the given source, returning the raw `token_get_all()` list.
     *
     * Suppresses tokeniser warnings for malformed sources — a third-party
     * module shipping a syntactically broken `.php` file (real-world: a
     * stray `app/code/Acme/Foo/.bak` file) shouldn't blow up the whole
     * scan; we just return an empty token list and move on.
     *
     * @return list<array{0:int,1:string,2:int}|string>
     */
    public static function tokenize(string $source): array
    {
        // T_OPEN_TAG awareness: token_get_all only emits tokens for code
        // inside open and close PHP tags. A file without an opening tag
        // is legitimate (Magento has a few view templates that include
        // other .phtml files) but contains no code we'd flag.
        return @token_get_all($source);
    }

    /**
     * Return the index of the next non-trivia token at or after $from.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    public static function nextNonTrivia(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from; $i < $count; $i++) {
            if (!self::isTrivia($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Return the index of the previous non-trivia token at or before $from.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    public static function previousNonTrivia(array $tokens, int $from): ?int
    {
        for ($i = $from; $i >= 0; $i--) {
            if (!self::isTrivia($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Return true if the token at $index is a function-call to the named
     * bareword (e.g. `shell_exec(`), not a method, static, or definition.
     *
     * Accepts an optional leading `T_NS_SEPARATOR` so `\shell_exec(` is
     * also detected. Rejects `Foo::shell_exec(`, `$x->shell_exec(`,
     * `function shell_exec(` and similar.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @param list<string>                             $names lowercase bareword names
     */
    public static function isFunctionCall(array $tokens, int $index, array $names): bool
    {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_STRING) {
            return false;
        }

        if (!in_array(strtolower($token[1]), $names, true)) {
            return false;
        }

        // Must be followed by `(` (skipping trivia).
        $next = self::nextNonTrivia($tokens, $index + 1);
        if ($next === null || $tokens[$next] !== '(') {
            return false;
        }

        // The immediately preceding non-trivia token must not flag this
        // as a method call, static call, definition, etc. A leading
        // namespace separator (`\shell_exec(...)`) is allowed — step
        // back past it before inspecting context.
        $prev = self::previousNonTrivia($tokens, $index - 1);
        if ($prev !== null && is_array($tokens[$prev]) && $tokens[$prev][0] === T_NS_SEPARATOR) {
            $prev = self::previousNonTrivia($tokens, $prev - 1);
        }

        if ($prev !== null && is_array($tokens[$prev]) && in_array($tokens[$prev][0], self::NON_CALL_PRECEDERS, true)) {
            return false;
        }

        return true;
    }

    /**
     * Extract an 80-character window of the original source around the
     * given line, used for the `snippet` field on findings.
     *
     * Source is split on \n; the matched line is centred in the window
     * with control characters collapsed to single spaces so a packed
     * one-liner remains readable in the report.
     */
    public static function snippet(string $source, int $line, int $width = 80): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $source) ?: [];
        $target = $lines[$line - 1] ?? '';

        // Collapse runs of control whitespace (tabs, repeated spaces)
        // into single spaces so the snippet reads as one tidy line.
        $collapsed = trim((string) preg_replace('/[\t ]+/', ' ', $target));

        if (mb_strlen($collapsed) <= $width) {
            return $collapsed;
        }

        return mb_substr($collapsed, 0, $width - 1) . '…';
    }

    /**
     * @param array{0:int,1:string,2:int}|string $token
     */
    private static function isTrivia(array|string $token): bool
    {
        if (!is_array($token)) {
            return false;
        }

        return in_array($token[0], self::TRIVIA_TOKENS, true);
    }
}
