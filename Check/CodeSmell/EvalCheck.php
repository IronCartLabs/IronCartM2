<?php

/**
 * IronCart_Scan — IC-050: `eval()` invocation in `app/code/**`.
 *
 * `eval()` in shipped Magento module code is virtually always a sign of
 * either a compromise (skimmer / webshell installer staging payloads at
 * runtime) or a carelessly-written module that opens a remote-code
 * execution gap. There is no legitimate use case in marketplace-grade
 * Magento module code.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\CodeSmell;

use IronCart\Scan\Report\Severity;

/**
 * IC-050 — flag `eval(` occurrences in `app/code/**\/*.php`.
 */
class EvalCheck extends AbstractCodeSmellCheck
{
    public const ID = 'IC-050';

    public function id(): string
    {
        return self::ID;
    }

    public function title(): string
    {
        return 'eval() invocation found in app/code';
    }

    public function severity(): string
    {
        return Severity::CRITICAL;
    }

    public function remediationUrl(): string
    {
        return 'https://ironcart.dev/docs/checks/IC-050';
    }

    /**
     * @inheritDoc
     */
    protected function scanTokens(array $tokens, string $source): array
    {
        $matches = [];

        // `eval` is a PHP language construct (not a function) and is
        // emitted by the tokeniser as its own token, T_EVAL. That gives
        // the IC-050 detector its zero-false-positive property — `eval`
        // inside a string literal becomes T_CONSTANT_ENCAPSED_STRING and
        // inside a comment becomes T_COMMENT, neither of which collides
        // with T_EVAL.
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_EVAL) {
                $matches[] = $token[2];
            }
        }

        return $matches;
    }
}
