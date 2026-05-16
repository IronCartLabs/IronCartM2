<?php

/**
 * IronCart_Scan — CspProbeRunner unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Runtime\Csp;

use IronCart\Scan\Check\Runtime\Csp\CspProbeRunner;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class CspProbeRunnerTest extends TestCase
{
    public function testSkipsWhenBaseUrlIsExampleCom(): void
    {
        $client = new FakeCspProbeClient(null);
        $runner = new CspProbeRunner(
            $this->storeManagerWithBaseUrl('http://example.com/'),
            $this->moduleListReturning('0.2.0'),
            $client
        );

        $result = $runner->probe();

        $this->assertFalse($result->probeAttempted);
        $this->assertSame(
            CspProbeRunner::SKIP_UNCONFIGURED_BASE_URL,
            $result->skipReason
        );
        $this->assertSame([], $client->calls, 'client must not be called when host is example.com');
    }

    public function testSkipsWhenBaseUrlIsExampleComWithWww(): void
    {
        $client = new FakeCspProbeClient(null);
        $runner = new CspProbeRunner(
            $this->storeManagerWithBaseUrl('https://www.example.com/'),
            $this->moduleListReturning('0.2.0'),
            $client
        );

        $result = $runner->probe();

        $this->assertSame(
            CspProbeRunner::SKIP_UNCONFIGURED_BASE_URL,
            $result->skipReason
        );
    }

    public function testProbesWhenBaseUrlIsLoopback(): void
    {
        $client = new FakeCspProbeClient([
            'content-security-policy' => "default-src 'self'",
        ]);
        $runner = new CspProbeRunner(
            $this->storeManagerWithBaseUrl('http://127.0.0.1/'),
            $this->moduleListReturning('0.2.0'),
            $client
        );

        $result = $runner->probe();

        $this->assertTrue($result->probeAttempted);
        $this->assertSame("default-src 'self'", $result->cspHeader);
        $this->assertCount(1, $client->calls);
        $this->assertSame('http://127.0.0.1/', $client->calls[0]['url']);
        $this->assertSame(
            'IronCart-Scan/0.2.0 (security-posture-check)',
            $client->calls[0]['userAgent']
        );
    }

    public function testProbeIsMemoised(): void
    {
        $client = new FakeCspProbeClient([
            'content-security-policy' => "default-src 'self'",
        ]);
        $runner = new CspProbeRunner(
            $this->storeManagerWithBaseUrl('http://127.0.0.1/'),
            $this->moduleListReturning('0.2.0'),
            $client
        );

        $runner->probe();
        $runner->probe();
        $runner->probe();

        $this->assertCount(1, $client->calls, 'probe() must memoise — one HTTP call per scan');
    }

    public function testReturnsSkipReasonWhenProbeFails(): void
    {
        $client = new FakeCspProbeClient(null); // transport failure
        $runner = new CspProbeRunner(
            $this->storeManagerWithBaseUrl('http://127.0.0.1/'),
            $this->moduleListReturning('0.2.0'),
            $client
        );

        $result = $runner->probe();

        $this->assertFalse($result->probeAttempted);
        $this->assertSame(CspProbeRunner::SKIP_PROBE_FAILED, $result->skipReason);
    }

    public function testReturnsSkipReasonWhenBaseUrlIsEmpty(): void
    {
        $client = new FakeCspProbeClient(null);
        $runner = new CspProbeRunner(
            $this->storeManagerWithBaseUrl(''),
            $this->moduleListReturning('0.2.0'),
            $client
        );

        $result = $runner->probe();

        $this->assertSame(CspProbeRunner::SKIP_NO_BASE_URL, $result->skipReason);
        $this->assertSame([], $client->calls);
    }

    public function testReportOnlyHeaderIsCaptured(): void
    {
        $client = new FakeCspProbeClient([
            'content-security-policy-report-only' => "default-src 'self'; report-uri /csp",
        ]);
        $runner = new CspProbeRunner(
            $this->storeManagerWithBaseUrl('http://127.0.0.1/'),
            $this->moduleListReturning('0.2.0'),
            $client
        );

        $result = $runner->probe();

        $this->assertTrue($result->probeAttempted);
        $this->assertNull($result->cspHeader);
        $this->assertSame(
            "default-src 'self'; report-uri /csp",
            $result->cspReportOnlyHeader
        );
    }

    public function testUaIncludesModuleVersionFromModuleList(): void
    {
        $client = new FakeCspProbeClient([]);
        $runner = new CspProbeRunner(
            $this->storeManagerWithBaseUrl('http://10.0.0.5/'),
            $this->moduleListReturning('9.9.9-rc.1'),
            $client
        );

        $runner->probe();

        $this->assertSame(
            'IronCart-Scan/9.9.9-rc.1 (security-posture-check)',
            $client->calls[0]['userAgent']
        );
    }

    private function storeManagerWithBaseUrl(string $url): StoreManagerInterface
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn($url);

        $manager = $this->createMock(StoreManagerInterface::class);
        $manager->method('getStore')->willReturn($store);

        return $manager;
    }

    private function moduleListReturning(string $version): ModuleListInterface
    {
        $moduleList = $this->createMock(ModuleListInterface::class);
        $moduleList->method('getOne')->willReturn([
            'name' => 'IronCart_Scan',
            'setup_version' => $version,
        ]);

        return $moduleList;
    }
}
