<?php

/**
 * IronCart_Scan — CspScriptSrcUnsafeCheck (IC-082) unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Runtime\Csp;

use IronCart\Scan\Check\Runtime\Csp\CspScriptSrcUnsafeCheck;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

class CspScriptSrcUnsafeCheckTest extends TestCase
{
    private CspCheckTestHelper $helper;

    protected function setUp(): void
    {
        $this->helper = new CspCheckTestHelper($this);
    }

    public function testEmitsHighOnUnsafeInline(): void
    {
        $check = new CspScriptSrcUnsafeCheck($this->helper->runnerWithHeaders([
            'content-security-policy' => "script-src 'self' 'unsafe-inline'",
        ]));

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame('IC-082', $findings[0]['id']);
        $this->assertSame(Severity::HIGH, $findings[0]['severity']);
        $this->assertSame(['\'unsafe-inline\''], $findings[0]['evidence']['offending_tokens']);
    }

    public function testEmitsHighOnUnsafeEval(): void
    {
        $check = new CspScriptSrcUnsafeCheck($this->helper->runnerWithHeaders([
            'content-security-policy' => "script-src 'self' 'unsafe-eval'",
        ]));

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame(['\'unsafe-eval\''], $findings[0]['evidence']['offending_tokens']);
    }

    public function testEmitsHighOnBothKeywords(): void
    {
        $check = new CspScriptSrcUnsafeCheck($this->helper->runnerWithHeaders([
            'content-security-policy' => "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
        ]));

        $findings = $check->run();

        $this->assertSame(
            ['\'unsafe-inline\'', '\'unsafe-eval\''],
            $findings[0]['evidence']['offending_tokens']
        );
    }

    public function testFallsBackToDefaultSrcWhenScriptSrcAbsent(): void
    {
        $check = new CspScriptSrcUnsafeCheck($this->helper->runnerWithHeaders([
            'content-security-policy' => "default-src 'self' 'unsafe-inline'",
        ]));

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame('default-src', $findings[0]['evidence']['directive']);
    }

    public function testPrefersScriptSrcOverDefaultSrc(): void
    {
        // If both are present we should look at script-src only — CSP3 §6.1.
        $check = new CspScriptSrcUnsafeCheck($this->helper->runnerWithHeaders([
            'content-security-policy' => "default-src 'unsafe-inline'; script-src 'self'",
        ]));

        $this->assertSame([], $check->run());
    }

    public function testSilentWhenScriptSrcSafe(): void
    {
        $check = new CspScriptSrcUnsafeCheck($this->helper->runnerWithHeaders([
            'content-security-policy' => "script-src 'self' 'nonce-abc' https://cdn.example.com",
        ]));

        $this->assertSame([], $check->run());
    }

    public function testFiresOnReportOnlyHeader(): void
    {
        // unsafe-inline in report-only mode is just as dangerous —
        // operators often forget to lock down the policy when they
        // promote it from report-only to enforced.
        $check = new CspScriptSrcUnsafeCheck($this->helper->runnerWithHeaders([
            'content-security-policy-report-only' => "script-src 'unsafe-inline'",
        ]));

        $this->assertCount(1, $check->run());
    }

    public function testCaseInsensitiveKeywordMatch(): void
    {
        $check = new CspScriptSrcUnsafeCheck($this->helper->runnerWithHeaders([
            'content-security-policy' => "script-src 'UNSAFE-INLINE'",
        ]));

        $this->assertCount(1, $check->run());
    }
}
