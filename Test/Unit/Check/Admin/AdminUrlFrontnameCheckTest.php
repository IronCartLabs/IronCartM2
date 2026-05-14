<?php

/**
 * IronCart_Scan — IC-010 unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Admin;

use IronCart\Scan\Check\Admin\AdminUrlFrontnameCheck;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class AdminUrlFrontnameCheckTest extends TestCase
{
    public function testDefaultFrontnameIsHigh(): void
    {
        $check = new AdminUrlFrontnameCheck($this->scopeConfig(false, 'admin'));

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame('IC-010', $findings[0]['id']);
        $this->assertSame(Severity::HIGH, $findings[0]['severity']);
        $this->assertTrue($findings[0]['evidence']['is_default']);
        $this->assertSame('admin', $findings[0]['evidence']['frontname']);
    }

    public function testCustomFrontnameIsInfo(): void
    {
        $check = new AdminUrlFrontnameCheck($this->scopeConfig(true, 'backoffice-9f2a'));

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::INFO, $findings[0]['severity']);
        $this->assertFalse($findings[0]['evidence']['is_default']);
        $this->assertSame('backoffice-9f2a', $findings[0]['evidence']['frontname']);
    }

    public function testUseCustomPathDisabledFallsBackToDefault(): void
    {
        // `use_custom_path` is off but a value is still stored: scanner should
        // treat the live frontname as the default and flag it `high`.
        $check = new AdminUrlFrontnameCheck($this->scopeConfig(false, 'backoffice-9f2a'));

        $findings = $check->run();

        $this->assertSame(Severity::HIGH, $findings[0]['severity']);
        $this->assertSame('admin', $findings[0]['evidence']['frontname']);
    }

    public function testRemediationUrlIsStable(): void
    {
        $check = new AdminUrlFrontnameCheck($this->scopeConfig(true, 'custom'));
        $findings = $check->run();

        $this->assertSame(
            'https://ironcart.dev/docs/checks/IC-010',
            $findings[0]['remediation_url']
        );
    }

    private function scopeConfig(bool $useCustom, string $customValue): ScopeConfigInterface
    {
        $stub = $this->createMock(ScopeConfigInterface::class);
        $stub->method('getValue')->willReturnCallback(
            static function (string $path) use ($useCustom, $customValue) {
                return match ($path) {
                    AdminUrlFrontnameCheck::USE_CUSTOM_PATH => $useCustom,
                    AdminUrlFrontnameCheck::CONFIG_PATH => $customValue,
                    default => null,
                };
            }
        );

        return $stub;
    }
}
