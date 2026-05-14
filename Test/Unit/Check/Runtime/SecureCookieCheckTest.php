<?php

/**
 * IronCart_Scan — SecureCookieCheck unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Runtime;

use IronCart\Scan\Check\Runtime\SecureCookieCheck;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class SecureCookieCheckTest extends TestCase
{
    public function testNoFindingWhenBothFlagsEnabled(): void
    {
        $check = new SecureCookieCheck($this->configWithFlags(true, true));

        $this->assertSame([], $check->run());
    }

    public function testHighFindingWhenHttpOnlyMissing(): void
    {
        $check = new SecureCookieCheck($this->configWithFlags(false, true));

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame(SecureCookieCheck::ID, $findings[0]['id']);
        $this->assertSame(Severity::HIGH, $findings[0]['severity']);
        $this->assertFalse($findings[0]['evidence']['web/cookie/cookie_httponly']);
        $this->assertTrue($findings[0]['evidence']['web/cookie/cookie_secure']);
    }

    public function testHighFindingWhenSecureMissing(): void
    {
        $check = new SecureCookieCheck($this->configWithFlags(true, false));

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::HIGH, $findings[0]['severity']);
    }

    public function testHighFindingWhenBothMissing(): void
    {
        $check = new SecureCookieCheck($this->configWithFlags(false, false));

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::HIGH, $findings[0]['severity']);
    }

    private function configWithFlags(bool $httpOnly, bool $secure): ScopeConfigInterface
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('isSetFlag')->willReturnMap([
            ['web/cookie/cookie_httponly', 'store', null, $httpOnly],
            ['web/cookie/cookie_secure', 'store', null, $secure],
        ]);

        return $config;
    }
}
