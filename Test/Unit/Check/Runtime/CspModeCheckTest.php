<?php

/**
 * IronCart_Scan — CspModeCheck unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Runtime;

use IronCart\Scan\Check\Runtime\CspModeCheck;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class CspModeCheckTest extends TestCase
{
    public function testEnforcedEmitsInfo(): void
    {
        $check = new CspModeCheck($this->configReturning('enforced'));
        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::INFO, $findings[0]['severity']);
    }

    public function testReportOnlyEmitsMedium(): void
    {
        $check = new CspModeCheck($this->configReturning('report-only'));
        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::MEDIUM, $findings[0]['severity']);
    }

    public function testMissingValueEmitsLow(): void
    {
        $check = new CspModeCheck($this->configReturning(null));
        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::LOW, $findings[0]['severity']);
    }

    public function testUnrecognisedValueEmitsLow(): void
    {
        $check = new CspModeCheck($this->configReturning('something-else'));
        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::LOW, $findings[0]['severity']);
    }

    public function testTrimAndCaseInsensitive(): void
    {
        $check = new CspModeCheck($this->configReturning('  ENFORCED  '));
        $findings = $check->run();

        $this->assertSame(Severity::INFO, $findings[0]['severity']);
    }

    private function configReturning(?string $value): ScopeConfigInterface
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn($value);

        return $config;
    }
}
