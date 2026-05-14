<?php

/**
 * IronCart_Scan — HttpsEnforcementCheck unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Runtime;

use IronCart\Scan\Check\Runtime\HttpsEnforcementCheck;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class HttpsEnforcementCheckTest extends TestCase
{
    public function testNoFindingWhenBothEnforced(): void
    {
        $check = new HttpsEnforcementCheck($this->configWithFlags(true, true));

        $this->assertSame([], $check->run());
    }

    public function testCriticalWhenAdminNotHttps(): void
    {
        $check = new HttpsEnforcementCheck($this->configWithFlags(true, false));

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::CRITICAL, $findings[0]['severity']);
        $this->assertStringContainsString('admin', strtolower($findings[0]['title']));
    }

    public function testHighWhenFrontendNotHttps(): void
    {
        $check = new HttpsEnforcementCheck($this->configWithFlags(false, true));

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::HIGH, $findings[0]['severity']);
        $this->assertStringContainsString('storefront', strtolower($findings[0]['title']));
    }

    public function testBothFindingsWhenNeitherEnforced(): void
    {
        $check = new HttpsEnforcementCheck($this->configWithFlags(false, false));

        $findings = $check->run();

        $this->assertCount(2, $findings);
        $severities = array_column($findings, 'severity');
        $this->assertContains(Severity::CRITICAL, $severities);
        $this->assertContains(Severity::HIGH, $severities);
    }

    /**
     * @param bool $frontend Value returned for `web/secure/use_in_frontend`.
     * @param bool $admin    Value returned for `web/secure/use_in_adminhtml`.
     */
    private function configWithFlags(bool $frontend, bool $admin): ScopeConfigInterface
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('isSetFlag')->willReturnMap([
            ['web/secure/use_in_frontend', 'store', null, $frontend],
            ['web/secure/use_in_adminhtml', 'store', null, $admin],
        ]);

        return $config;
    }
}
