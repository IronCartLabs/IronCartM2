<?php

/**
 * IronCart_Scan — CspBaseUrlConfiguredCheck (IC-085) unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Runtime\Csp;

use IronCart\Scan\Check\Runtime\Csp\CspBaseUrlConfiguredCheck;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

class CspBaseUrlConfiguredCheckTest extends TestCase
{
    private CspCheckTestHelper $helper;

    protected function setUp(): void
    {
        $this->helper = new CspCheckTestHelper($this);
    }

    public function testEmitsLowWhenBaseUrlIsExampleCom(): void
    {
        $check = new CspBaseUrlConfiguredCheck($this->helper->unconfiguredRunner());

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame('IC-085', $findings[0]['id']);
        $this->assertSame(Severity::LOW, $findings[0]['severity']);
        $this->assertSame(
            'http://example.com/',
            $findings[0]['evidence']['configured_base_url']
        );
    }

    public function testSilentWhenBaseUrlIsLocalhost(): void
    {
        $check = new CspBaseUrlConfiguredCheck($this->helper->runnerWithHeaders([], 'http://127.0.0.1/'));

        $this->assertSame([], $check->run());
    }

    public function testSilentWhenBaseUrlIsRealHostname(): void
    {
        $check = new CspBaseUrlConfiguredCheck($this->helper->runnerWithHeaders([], 'https://store.example.test/'));

        $this->assertSame([], $check->run());
    }
}
