<?php

/**
 * IronCart_Scan — IC-053: shell execution from PHP.
 *
 * `shell_exec`, `exec`, `passthru`, `system`, `popen`, `proc_open`, and
 * the backtick operator all hand a string to `/bin/sh` (or `cmd.exe` on
 * Windows). Even when the immediate argument is a literal, the existence
 * of these calls in a Magento module is a strong signal of either a
 * post-exploitation webshell or an integration that should be reviewed.
 *
 * The check does not attempt to taint-trace the argument — that's the
 * AST-level work the issue body explicitly defers. The flag itself, with
 * the snippet evidence, is what the security reviewer needs to triage.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\CodeSmell;

use IronCart\Scan\Report\Severity;

/**
 * IC-053 — flag shell-execution calls in `app/code/**\/*.php`.
 */
class ShellExecCheck extends AbstractCodeSmellCheck
{
    public const ID = 'IC-053';

    /**
     * Function names that route to the OS shell. All lowercase — PHP
     * function names are case-insensitive but `T_STRING` content is
     * returned verbatim, so we lower-case before comparison.
     *
     * @var list<string>
     */
    private const SHELL_FUNCTIONS = [
        'shell_exec',
        'exec',
        'passthru',
        'system',
        'popen',
        'proc_open',
    ];

    public function id(): string
    {
        return self::ID;
    }

    public function title(): string
    {
        return 'Shell execution from PHP';
    }

    public function severity(): string
    {
        return Severity::HIGH;
    }

    public function remediationUrl(): string
    {
        return 'https://ironcart.dev/docs/checks/IC-053';
    }

    /**
     * @inheritDoc
     */
    protected function scanTokens(array $tokens, string $source): array
    {
        $matches = [];

        // (1) Named function-call form: shell_exec(...), exec(...), etc.
        foreach ($tokens as $i => $token) {
            if (TokenScanner::isFunctionCall($tokens, $i, self::SHELL_FUNCTIONS)) {
                // isFunctionCall() guarantees $token is a T_STRING
                // token array; line number lives at index 2.
                /** @var array{0:int,1:string,2:int} $token */
                $matches[] = $token[2];
            }
        }

        // (2) Backtick operator: `whoami`. The tokeniser emits the
        // opening and closing backticks as single-char string tokens
        // `` ` ``; the contents in between are T_ENCAPSED_AND_WHITESPACE
        // / T_VARIABLE. Backticks inside a PHP string literal are folded
        // into T_CONSTANT_ENCAPSED_STRING and never surface as bare ` `
        // tokens, so the false-positive class we worry about elsewhere
        // doesn't apply here.
        //
        // Backticks cannot nest in valid PHP — a simple toggle is the
        // canonical pair-walk. We flag every opening backtick once.
        // Single-char tokens don't carry line metadata, so we keep a
        // running `currentLine` from the most recent line-bearing token.
        $insideBacktick = false;
        $currentLine = 1;
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $currentLine = $token[2];
                continue;
            }
            if ($token === '`') {
                if (!$insideBacktick) {
                    $matches[] = $currentLine;
                }
                $insideBacktick = !$insideBacktick;
            }
        }

        sort($matches);

        return $matches;
    }
}
