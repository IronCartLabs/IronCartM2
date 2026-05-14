<?php

/**
 * IronCart_Scan — MageModeCheck unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Runtime;

use IronCart\Scan\Check\Runtime\MageModeCheck;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

class MageModeCheckTest extends TestCase
{
    public function testNoFindingWhenModeIsProduction(): void
    {
        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn(State::MODE_PRODUCTION);

        $config = $this->createMock(ScopeConfigInterface::class);

        $check = new MageModeCheck($state, $config);

        $this->assertSame([], $check->run());
    }

    public function testNoFindingWhenDeveloperModeOnLocalhost(): void
    {
        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn(State::MODE_DEVELOPER);

        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnMap([
            ['web/unsecure/base_url', 'store', null, 'http://localhost/'],
            ['web/secure/base_url', 'store', null, 'http://magento.test/'],
        ]);

        $check = new MageModeCheck($state, $config);

        $this->assertSame([], $check->run());
    }

    public function testCriticalFindingWhenDeveloperModeOnPublicHost(): void
    {
        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn(State::MODE_DEVELOPER);

        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnMap([
            ['web/unsecure/base_url', 'store', null, 'https://shop.example.com/'],
            ['web/secure/base_url', 'store', null, 'https://shop.example.com/'],
        ]);

        $check = new MageModeCheck($state, $config);

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame(MageModeCheck::ID, $findings[0]['id']);
        $this->assertSame(Severity::CRITICAL, $findings[0]['severity']);
        $this->assertSame(State::MODE_DEVELOPER, $findings[0]['evidence']['mage_mode']);
    }

    public function testStateExceptionFallsBackToDefaultMode(): void
    {
        $state = $this->createMock(State::class);
        $state->method('getMode')->willThrowException(new \LogicException('not ready'));

        $config = $this->createMock(ScopeConfigInterface::class);

        $check = new MageModeCheck($state, $config);

        $this->assertSame([], $check->run());
    }
}
