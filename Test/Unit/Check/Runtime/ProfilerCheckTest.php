<?php

/**
 * IronCart_Scan — ProfilerCheck unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Runtime;

use IronCart\Scan\Check\Runtime\MagentoModeReader;
use IronCart\Scan\Check\Runtime\ProfilerCheck;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

class ProfilerCheckTest extends TestCase
{
    public function testNoFindingWhenNotProduction(): void
    {
        $reader = $this->readerReturning(State::MODE_DEVELOPER);

        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('isSetFlag')->willReturn(true);

        $check = new ProfilerCheck($reader, $config);

        $this->assertSame([], $check->run());
    }

    public function testNoFindingWhenProfilerOff(): void
    {
        $reader = $this->readerReturning(State::MODE_PRODUCTION);

        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('isSetFlag')->willReturn(false);

        $check = new ProfilerCheck($reader, $config);

        $this->assertSame([], $check->run());
    }

    public function testMediumWhenProfilerOnInProduction(): void
    {
        $reader = $this->readerReturning(State::MODE_PRODUCTION);

        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('isSetFlag')->willReturn(true);

        $check = new ProfilerCheck($reader, $config);

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::MEDIUM, $findings[0]['severity']);
        $this->assertSame(ProfilerCheck::ID, $findings[0]['id']);
    }

    private function readerReturning(string $mode): MagentoModeReader
    {
        $reader = $this->createMock(MagentoModeReader::class);
        $reader->method('mode')->willReturn($mode);

        return $reader;
    }
}
