<?php

/**
 * IronCart_Scan — CspHeaderParser unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Runtime\Csp;

use IronCart\Scan\Check\Runtime\Csp\CspHeaderParser;
use PHPUnit\Framework\TestCase;

class CspHeaderParserTest extends TestCase
{
    public function testEmptyHeaderReturnsEmptyMap(): void
    {
        $this->assertSame([], CspHeaderParser::parse(''));
        $this->assertSame([], CspHeaderParser::parse('   '));
    }

    public function testSingleDirective(): void
    {
        $this->assertSame(
            ['default-src' => ["'self'"]],
            CspHeaderParser::parse("default-src 'self'")
        );
    }

    public function testMultipleDirectivesAreSplitOnSemicolon(): void
    {
        $result = CspHeaderParser::parse(
            "default-src 'self'; script-src 'self' 'unsafe-inline'; report-uri /csp-report"
        );

        $this->assertSame(["'self'"], $result['default-src']);
        $this->assertSame(["'self'", "'unsafe-inline'"], $result['script-src']);
        $this->assertSame(['/csp-report'], $result['report-uri']);
    }

    public function testDirectiveNameIsLowercased(): void
    {
        $result = CspHeaderParser::parse("Script-Src 'self'; FRAME-ANCESTORS *");

        $this->assertArrayHasKey('script-src', $result);
        $this->assertArrayHasKey('frame-ancestors', $result);
        $this->assertSame(['*'], $result['frame-ancestors']);
    }

    public function testCommaJoinedPoliciesAreMerged(): void
    {
        $result = CspHeaderParser::parse(
            "default-src 'self', script-src 'unsafe-inline'"
        );

        $this->assertSame(["'self'"], $result['default-src']);
        $this->assertSame(["'unsafe-inline'"], $result['script-src']);
    }

    public function testSameDirectiveAcrossPoliciesMergesTokens(): void
    {
        $result = CspHeaderParser::parse(
            "script-src 'self', script-src 'unsafe-inline'"
        );

        $this->assertSame(["'self'", "'unsafe-inline'"], $result['script-src']);
    }

    public function testEmptyDirectivesAreSkipped(): void
    {
        $result = CspHeaderParser::parse(";; default-src 'self';;;");

        $this->assertSame(['default-src' => ["'self'"]], $result);
    }

    public function testExtraWhitespaceIsTolerated(): void
    {
        $result = CspHeaderParser::parse("  default-src    'self'  'https:'   ");

        $this->assertSame(["'self'", "'https:'"], $result['default-src']);
    }
}
