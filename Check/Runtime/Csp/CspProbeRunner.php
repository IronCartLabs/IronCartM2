<?php

/**
 * IronCart_Scan — shared storefront-CSP probe runner.
 *
 * Issues a single HEAD request to the storefront base URL and caches
 * the result for the lifetime of the scan, so the six IC-08x checks
 * each consult the same probe outcome rather than firing six separate
 * requests.
 *
 * Responsibilities:
 *
 *   1. Resolve the configured base URL via {@see StoreManagerInterface}.
 *   2. Detect Magento's untouched `http://example.com/` default and
 *      record `'unconfigured-base-url'` as the skip reason — this is
 *      what IC-085 keys off.
 *   3. Validate the URL through {@see LoopbackHostGuard}. If the host
 *      is neither loopback nor RFC1918 nor the configured base hostname,
 *      record `'unsafe-host'` as the skip reason and DO NOT probe.
 *   4. Otherwise call the injected {@see CspProbeClient} once and
 *      memoise the result.
 *
 * The runner is registered as a `shared="true"` DI dependency in
 * `etc/di.xml` so all six checks see the cached result.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Runtime\Csp;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Coordinates the single HEAD probe shared by the IC-08x check pack.
 */
class CspProbeRunner
{
    public const SKIP_UNCONFIGURED_BASE_URL = 'unconfigured-base-url';
    public const SKIP_UNSAFE_HOST = 'unsafe-host';
    public const SKIP_PROBE_FAILED = 'probe-failed';
    public const SKIP_NO_BASE_URL = 'no-base-url';

    private const MODULE_NAME = 'IronCart_Scan';

    /**
     * Hosts that indicate Magento was installed with the default placeholder
     * base URL. `setup:install` defaults to `http://example.com/` and the
     * Admin UI nudges the operator to change it; if either still holds at
     * scan time the storefront is unconfigured.
     */
    private const PLACEHOLDER_HOSTS = ['example.com', 'www.example.com'];

    private ?CspProbeResult $cached = null;

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ModuleListInterface $moduleList,
        private readonly CspProbeClient $client
    ) {
    }

    /**
     * Return the (memoised) probe result.
     */
    public function probe(): CspProbeResult
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $baseUrl = $this->resolveBaseUrl();
        if ($baseUrl === null) {
            return $this->cached = CspProbeResult::skipped('', self::SKIP_NO_BASE_URL);
        }

        $host = LoopbackHostGuard::extractHost($baseUrl);
        if ($host === null) {
            return $this->cached = CspProbeResult::skipped($baseUrl, self::SKIP_NO_BASE_URL);
        }

        if (in_array($host, self::PLACEHOLDER_HOSTS, true)) {
            return $this->cached = CspProbeResult::skipped(
                $baseUrl,
                self::SKIP_UNCONFIGURED_BASE_URL
            );
        }

        if (!LoopbackHostGuard::isAllowed($baseUrl, $baseUrl)) {
            return $this->cached = CspProbeResult::skipped(
                $baseUrl,
                self::SKIP_UNSAFE_HOST
            );
        }

        $headers = $this->client->head($baseUrl, $this->userAgent());
        if ($headers === null) {
            return $this->cached = CspProbeResult::skipped(
                $baseUrl,
                self::SKIP_PROBE_FAILED
            );
        }

        return $this->cached = CspProbeResult::probed(
            $baseUrl,
            $headers['content-security-policy'] ?? null,
            $headers['content-security-policy-report-only'] ?? null
        );
    }

    /**
     * Resolve the current store's base URL.
     */
    private function resolveBaseUrl(): ?string
    {
        try {
            $store = $this->storeManager->getStore();
        } catch (NoSuchEntityException) {
            return null;
        } catch (\Throwable) {
            return null;
        }

        $url = (string) $store->getBaseUrl();

        return $url === '' ? null : $url;
    }

    /**
     * Build the User-Agent string for the probe.
     *
     * Falls back to `0.0.0` if the module version can't be resolved
     * (defensive — should only happen if `etc/module.xml` is malformed).
     */
    private function userAgent(): string
    {
        $module = $this->moduleList->getOne(self::MODULE_NAME);
        $version = is_array($module) && isset($module['setup_version'])
            ? (string) $module['setup_version']
            : '0.0.0';
        if ($version === '') {
            $version = '0.0.0';
        }

        return sprintf('IronCart-Scan/%s (security-posture-check)', $version);
    }
}
