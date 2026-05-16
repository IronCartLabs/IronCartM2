<?php

/**
 * IronCart_Scan — CspMissingCheck (IC-080) unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Runtime\Csp;

use IronCart\Scan\Check\Runtime\Csp\CspMissingCheck;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

class CspMissingCheckTest extends TestCase
{
    private CspCheckTestHelper $helper;

    protected function setUp(): void
    {
        $this->helper = new CspCheckTestHelper($this);
    }

    public function testEmitsHighWhenNoCspHeader(): void
    {
        $check = new CspMissingCheck($this->helper->runnerWithHeaders([]));
        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame('IC-080', $findings[0]['id']);
        $this->assertSame(Severity::HIGH, $findings[0]['severity']);
        $this->assertSame('http://127.0.0.1/', $findings[0]['evidence']['probed_url']);
        $this->assertSame(
            'https://ironcart.dev/docs/checks/IC-080',
            $findings[0]['remediation_url']
        );
    }

    public function testSilentWhenCspHeaderPresent(): void
    {
        $check = new CspMissingCheck($this->helper->runnerWithHeaders([
            'content-security-policy' => "default-src 'self'",
        ]));

        $this->assertSame([], $check->run());
    }

    public function testSilentWhenOnlyReportOnlyHeaderPresent(): void
    {
        // IC-080 considers a report-only header to count as "CSP is present";
        // IC-084 owns the report-only-in-production case.
        $check = new CspMissingCheck($this->helper->runnerWithHeaders([
            'content-security-policy-report-only' => "default-src 'self'",
        ]));

        $this->assertSame([], $check->run());
    }

    public function testSilentWhenProbeSkipped(): void
    {
        $check = new CspMissingCheck($this->helper->unconfiguredRunner());

        $this->assertSame([], $check->run());
    }
}
