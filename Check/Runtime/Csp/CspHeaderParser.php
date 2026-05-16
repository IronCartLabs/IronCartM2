<?php

/**
 * IronCart_Scan — Content-Security-Policy header parser.
 *
 * Parses one or more CSP header values into a directive => token-list map.
 * Used by the IC-08x posture checks to test for individual directives
 * (`script-src`, `frame-ancestors`, `report-uri`, etc.) without each check
 * re-implementing CSP grammar.
 *
 * The parser is intentionally permissive: it lowercases directive names,
 * collapses whitespace, and ignores empty directives. It does NOT validate
 * token grammar — the IC-08x checks use simple `in_array` membership tests
 * against the returned tokens, which is sufficient for the posture
 * decisions we make (`unsafe-inline`/`unsafe-eval`/`*`/etc.).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Runtime\Csp;

/**
 * Pure-function CSP header parser. No state, no DI dependencies — safe to
 * call from any check.
 */
final class CspHeaderParser
{
    private function __construct()
    {
    }

    /**
     * Parse a CSP header value (or a comma-joined list of multiple header
     * values, as returned by some HTTP clients) into a directive map.
     *
     * Example:
     *   "default-src 'self'; script-src 'self' 'unsafe-inline'; report-uri /csp"
     *   →
     *   [
     *     'default-src' => ["'self'"],
     *     'script-src'  => ["'self'", "'unsafe-inline'"],
     *     'report-uri'  => ['/csp'],
     *   ]
     *
     * @return array<string, list<string>>
     */
    public static function parse(string $headerValue): array
    {
        $headerValue = trim($headerValue);
        if ($headerValue === '') {
            return [];
        }

        $directives = [];
        // CSP allows multiple policies separated by `,`. RFC 7762 says
        // "the user agent enforces them all". We merge them so a check
        // sees the union of every policy's directive — which is the
        // worst-case posture from a defender's perspective (e.g. if any
        // policy includes `unsafe-inline`, the page can execute inline
        // script regardless of what the other policies say).
        foreach (explode(',', $headerValue) as $policy) {
            foreach (explode(';', $policy) as $directive) {
                $directive = trim($directive);
                if ($directive === '') {
                    continue;
                }

                $parts = preg_split('/\s+/', $directive);
                if ($parts === false || $parts === []) {
                    continue;
                }

                $name = strtolower(array_shift($parts));
                if ($name === '') {
                    continue;
                }

                $tokens = array_values(array_filter(
                    $parts,
                    static fn (string $t): bool => $t !== ''
                ));

                if (!isset($directives[$name])) {
                    $directives[$name] = [];
                }
                foreach ($tokens as $token) {
                    $directives[$name][] = $token;
                }
            }
        }

        return $directives;
    }
}
