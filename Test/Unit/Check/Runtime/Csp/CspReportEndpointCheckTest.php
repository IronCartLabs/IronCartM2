<?php

/**
 * IronCart_Scan — CspReportEndpointCheck (IC-081) unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Runtime\Csp;

use IronCart\Scan\Check\Runtime\Csp\CspReportEndpointCheck;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

class CspReportEndpointCheckTest extends TestCase
{
    private CspCheckTestHelper $helper;

    protected function setUp(): void
    {
        $this->helper = new CspCheckTestHelper($this);
    }

    public function testEmitsMediumWhenNeitherReportDirectiveSet(): void
    {
        $check = new CspReportEndpointCheck($this->helper->runnerWithHeaders([
            'content-security-policy' => "default-src 'self'; script-src 'self'",
        ]));

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame('IC-081', $findings[0]['id']);
        $this->assertSame(Severity::MEDIUM, $findings[0]['severity']);
    }

    public function testSilentWhenReportUriPresent(): void
    {
        $check = new CspReportEndpointCheck($this->helper->runnerWithHeaders([
            'content-security-policy' => "default-src 'self'; report-uri /csp-violation",
        ]));

        $this->assertSame([], $check->run());
    }

    public function testSilentWhenReportToPresent(): void
    {
        $check = new CspReportEndpointCheck($this->helper->runnerWithHeaders([
            'content-security-policy' => "default-src 'self'; report-to csp-endpoint",
        ]));

        $this->assertSame([], $check->run());
    }

    public function testFiresOnReportOnlyHeaderToo(): void
    {
        // Operators routinely run report-only with NO reporting endpoint
        // during a CSP rollout — that's the worst case because the violations
        // are silently dropped. Make sure we still flag it.
        $check = new CspReportEndpointCheck($this->helper->runnerWithHeaders([
            'content-security-policy-report-only' => "default-src 'self'",
        ]));

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame(
            'content-security-policy-report-only',
            $findings[0]['evidence']['csp_header_source']
        );
    }

    public function testSilentWhenNoCspAtAll(): void
    {
        $check = new CspReportEndpointCheck($this->helper->runnerWithHeaders([]));

        $this->assertSame([], $check->run(), 'IC-080 owns the "no CSP at all" case');
    }
}
