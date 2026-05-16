<?php

/**
 * IronCart_Scan — IC-051: `unserialize()` on untrusted superglobals.
 *
 * Passing `$_REQUEST`, `$_GET`, `$_POST`, or `$_COOKIE` directly to
 * `unserialize()` is a classic PHP object-injection / RCE vector — every
 * gadget chain published against Magento (and against other PHP CMSes)
 * starts here. PHP 7.0 introduced the `allowed_classes` option but it's
 * not opt-out, so legacy modules continue to ship the bare two-arg form.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\CodeSmell;

use IronCart\Scan\Report\Severity;

/**
 * IC-051 — flag `unserialize($_REQUEST|$_GET|$_POST|$_COOKIE` calls.
 */
class UnserializeUntrustedCheck extends AbstractCodeSmellCheck
{
    public const ID = 'IC-051';

    /**
     * Superglobals that are always under attacker control.
     *
     * `$_SERVER` is excluded — large parts of it are also attacker-
     * controlled, but the high-confidence subset for IC-051 is the
     * request-body superglobals.
     *
     * @var list<string>
     */
    private const UNTRUSTED_SUPERGLOBALS = [
        '$_REQUEST',
        '$_GET',
        '$_POST',
        '$_COOKIE',
    ];

    public function id(): string
    {
        return self::ID;
    }

    public function title(): string
    {
        return 'Unserialize on untrusted input — RCE vector';
    }

    public function severity(): string
    {
        return Severity::CRITICAL;
    }

    public function remediationUrl(): string
    {
        return 'https://ironcart.dev/docs/checks/IC-051';
    }

    /**
     * @inheritDoc
     */
    protected function scanTokens(array $tokens, string $source): array
    {
        $matches = [];

        foreach ($tokens as $i => $token) {
            if (!TokenScanner::isFunctionCall($tokens, $i, ['unserialize'])) {
                continue;
            }

            // Token after the `(` — the first argument's leading
            // identifier. If it's one of our untrusted superglobals, flag.
            $paren = TokenScanner::nextNonTrivia($tokens, $i + 1);
            if ($paren === null) {
                continue;
            }
            $arg = TokenScanner::nextNonTrivia($tokens, $paren + 1);
            if ($arg === null) {
                continue;
            }

            $argToken = $tokens[$arg];
            if (!is_array($argToken) || $argToken[0] !== T_VARIABLE) {
                continue;
            }

            if (!in_array($argToken[1], self::UNTRUSTED_SUPERGLOBALS, true)) {
                continue;
            }

            // isFunctionCall() above guarantees $token is the T_STRING
            // array form; the line number lives at index 2.
            /** @var array{0:int,1:string,2:int} $token */
            $matches[] = $token[2];
        }

        return $matches;
    }
}
