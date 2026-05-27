<?php

/**
 * IronCart_Scan — CspReportOnlyInProductionCheck (IC-084) unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Runtime\Csp;

use IronCart\Scan\Check\Runtime\Csp\CspReportOnlyInProductionCheck;
use IronCart\Scan\Check\Runtime\MagentoModeReader;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

class CspReportOnlyInProductionCheckTest extends TestCase
{
    private CspCheckTestHelper $helper;

    protected function setUp(): void
    {
        $this->helper = new CspCheckTestHelper($this);
    }

    public function testEmitsHighWhenReportOnlyInProduction(): void
    {
        $check = new CspReportOnlyInProductionCheck(
            $this->helper->runnerWithHeaders([
                'content-security-policy-report-only' => "default-src 'self'",
            ]),
            $this->readerInMode(State::MODE_PRODUCTION)
        );

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame('IC-084', $findings[0]['id']);
        $this->assertSame(Severity::HIGH, $findings[0]['severity']);
        $this->assertSame('production', $findings[0]['evidence']['mage_mode']);
    }

    public function testSilentInDeveloperMode(): void
    {
        $check = new CspReportOnlyInProductionCheck(
            $this->helper->runnerWithHeaders([
                'content-security-policy-report-only' => "default-src 'self'",
            ]),
            $this->readerInMode(State::MODE_DEVELOPER)
        );

        $this->assertSame([], $check->run());
    }

    public function testSilentInDefaultMode(): void
    {
        $check = new CspReportOnlyInProductionCheck(
            $this->helper->runnerWithHeaders([
                'content-security-policy-report-only' => "default-src 'self'",
            ]),
            $this->readerInMode(State::MODE_DEFAULT)
        );

        $this->assertSame([], $check->run());
    }

    public function testSilentWhenEnforcedCspIsAlsoPresent(): void
    {
        // The whole point of IC-084 is "report-only is the ONLY policy" —
        // if there's an enforced CSP too, the operator already gets blocking.
        $check = new CspReportOnlyInProductionCheck(
            $this->helper->runnerWithHeaders([
                'content-security-policy' => "default-src 'self'",
                'content-security-policy-report-only' => "default-src 'self'",
            ]),
            $this->readerInMode(State::MODE_PRODUCTION)
        );

        $this->assertSame([], $check->run());
    }

    public function testSilentWhenNoCspAtAll(): void
    {
        $check = new CspReportOnlyInProductionCheck(
            $this->helper->runnerWithHeaders([]),
            $this->readerInMode(State::MODE_PRODUCTION)
        );

        $this->assertSame([], $check->run(), 'IC-080 owns the "no CSP at all" case');
    }

    public function testSilentWhenProbeSkipped(): void
    {
        $check = new CspReportOnlyInProductionCheck(
            $this->helper->unconfiguredRunner(),
            $this->readerInMode(State::MODE_PRODUCTION)
        );

        $this->assertSame([], $check->run());
    }

    private function readerInMode(string $mode): MagentoModeReader
    {
        $reader = $this->createMock(MagentoModeReader::class);
        $reader->method('mode')->willReturn($mode);

        return $reader;
    }
}
