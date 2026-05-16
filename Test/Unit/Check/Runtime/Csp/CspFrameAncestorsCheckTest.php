<?php

/**
 * IronCart_Scan — CspFrameAncestorsCheck (IC-083) unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Runtime\Csp;

use IronCart\Scan\Check\Runtime\Csp\CspFrameAncestorsCheck;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

class CspFrameAncestorsCheckTest extends TestCase
{
    private CspCheckTestHelper $helper;

    protected function setUp(): void
    {
        $this->helper = new CspCheckTestHelper($this);
    }

    public function testEmitsMediumWhenDirectiveAbsent(): void
    {
        $check = new CspFrameAncestorsCheck($this->helper->runnerWithHeaders([
            'content-security-policy' => "default-src 'self'",
        ]));

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame('IC-083', $findings[0]['id']);
        $this->assertSame(Severity::MEDIUM, $findings[0]['severity']);
        $this->assertStringContainsString('no frame-ancestors', $findings[0]['title']);
    }

    public function testEmitsMediumWhenSetToWildcard(): void
    {
        $check = new CspFrameAncestorsCheck($this->helper->runnerWithHeaders([
            'content-security-policy' => "default-src 'self'; frame-ancestors *",
        ]));

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('frame-ancestors is set to *', $findings[0]['title']);
        $this->assertSame(['*'], $findings[0]['evidence']['frame_ancestors']);
    }

    public function testSilentWhenSetToSelf(): void
    {
        $check = new CspFrameAncestorsCheck($this->helper->runnerWithHeaders([
            'content-security-policy' => "frame-ancestors 'self'",
        ]));

        $this->assertSame([], $check->run());
    }

    public function testSilentWhenSetToNone(): void
    {
        $check = new CspFrameAncestorsCheck($this->helper->runnerWithHeaders([
            'content-security-policy' => "frame-ancestors 'none'",
        ]));

        $this->assertSame([], $check->run());
    }

    public function testSilentWhenAllowList(): void
    {
        $check = new CspFrameAncestorsCheck($this->helper->runnerWithHeaders([
            'content-security-policy' => "frame-ancestors 'self' https://partner.example.com",
        ]));

        $this->assertSame([], $check->run());
    }

    public function testFiresOnReportOnlyHeader(): void
    {
        $check = new CspFrameAncestorsCheck($this->helper->runnerWithHeaders([
            'content-security-policy-report-only' => "default-src 'self'; frame-ancestors *",
        ]));

        $this->assertCount(1, $check->run());
    }

    public function testSilentWhenNoCspAtAll(): void
    {
        $check = new CspFrameAncestorsCheck($this->helper->runnerWithHeaders([]));

        $this->assertSame([], $check->run(), 'IC-080 owns the "no CSP at all" case');
    }
}
