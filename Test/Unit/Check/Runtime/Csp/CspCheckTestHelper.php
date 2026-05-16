<?php

/**
 * IronCart_Scan — shared helper for the IC-08x check tests.
 *
 * Builds a real {@see CspProbeRunner} backed by a {@see FakeCspProbeClient}
 * so check tests stay focused on the finding shape rather than wiring
 * StoreManager / ModuleList mocks each time.
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

/**
 * Mixin-style helper. Test classes use it via composition (own one
 * instance, delegate to it) rather than inheritance so they keep
 * standalone {@see TestCase} subclasses.
 */
final class CspCheckTestHelper
{
    public function __construct(private readonly TestCase $tc)
    {
    }

    /**
     * Build a runner that returns `$headers` from its single probe.
     *
     * @param array<string, string> $headers
     */
    public function runnerWithHeaders(
        array $headers,
        string $baseUrl = 'http://127.0.0.1/'
    ): CspProbeRunner {
        return new CspProbeRunner(
            $this->storeManagerWithBaseUrl($baseUrl),
            $this->moduleListReturning('0.2.0'),
            new FakeCspProbeClient($headers)
        );
    }

    /**
     * Build a runner whose probe is skipped because the base URL is
     * the unconfigured `example.com` default.
     */
    public function unconfiguredRunner(): CspProbeRunner
    {
        return new CspProbeRunner(
            $this->storeManagerWithBaseUrl('http://example.com/'),
            $this->moduleListReturning('0.2.0'),
            new FakeCspProbeClient(null)
        );
    }

    private function storeManagerWithBaseUrl(string $url): StoreManagerInterface
    {
        // Mocked against Store (concrete model) rather than the
        // StoreInterface API interface because `getBaseUrl()` is declared
        // on Store directly and the IC-08x runner depends on it.
        $store = $this->tc->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn($url);

        $manager = $this->tc->createMock(StoreManagerInterface::class);
        $manager->method('getStore')->willReturn($store);

        return $manager;
    }

    private function moduleListReturning(string $version): ModuleListInterface
    {
        $moduleList = $this->tc->createMock(ModuleListInterface::class);
        $moduleList->method('getOne')->willReturn([
            'name' => 'IronCart_Scan',
            'setup_version' => $version,
        ]);

        return $moduleList;
    }
}
