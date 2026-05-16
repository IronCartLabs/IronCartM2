<?php

/**
 * IronCart_Scan — loopback / store-local host guard for the CSP probe.
 *
 * Hard-codes the policy that the CSP posture check pack (IC-080..IC-085)
 * is only permitted to probe the *merchant's own storefront*, never an
 * arbitrary URL. The module's read-only invariant must hold even for the
 * one network-touching check pack v2 introduces — see the v2 scope
 * decision in `.claude/memory/project_open_decisions.md` (2026-05-16).
 *
 * Acceptable hosts:
 *   - `localhost`, `127.0.0.1`, `::1`, `[::1]`
 *   - any address in an RFC1918 / RFC4193 / RFC3927 private range
 *   - the hostname configured as the store base URL (so a self-hosted
 *     box at `magento.example.com` can probe itself in production)
 *
 * Everything else (including arbitrary public hostnames that happen to
 * be the operator's own storefront on a multi-tenant proxy) is rejected
 * by default to keep the blast radius of any future code-injection in
 * the probe stack near zero.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Runtime\Csp;

/**
 * Pure-function gate that decides whether `$probeUrl` may be probed.
 *
 * The guard is intentionally permissive about the configured-base-URL case
 * because Magento installs vary: a merchant might serve `magento.test`
 * (resolves to 127.0.0.1 via /etc/hosts) OR a public hostname that the
 * Magento box itself owns. We accept either as long as the URL we probe
 * matches what Magento itself believes its base URL is — i.e. we never
 * probe a *different* hostname than the operator has configured.
 */
final class LoopbackHostGuard
{
    private function __construct()
    {
    }

    /**
     * @param string $probeUrl    The URL the probe is about to call.
     * @param string $configuredBaseUrl  The store's configured base URL
     *                                   (from `Magento\Store\Model\StoreManagerInterface`).
     */
    public static function isAllowed(string $probeUrl, string $configuredBaseUrl): bool
    {
        $probeHost = self::extractHost($probeUrl);
        if ($probeHost === null) {
            return false;
        }

        if (self::isLoopback($probeHost)) {
            return true;
        }

        if (self::isPrivateAddress($probeHost)) {
            return true;
        }

        $configuredHost = self::extractHost($configuredBaseUrl);
        if ($configuredHost !== null && strcasecmp($probeHost, $configuredHost) === 0) {
            return true;
        }

        return false;
    }

    /**
     * Extract a lowercased hostname from a URL. Returns null on parse
     * failure or empty host. Strips surrounding brackets from IPv6
     * literals so the consumer can compare them as plain addresses.
     */
    public static function extractHost(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);
        // parse_url returns `[::1]` with brackets for IPv6 — strip them
        // so filter_var / in_array comparisons see the bare literal.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return $host;
    }

    private static function isLoopback(string $host): bool
    {
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        // Treat `*.localhost` as loopback per RFC 6761 §6.3.
        if (str_ends_with($host, '.localhost')) {
            return true;
        }

        return false;
    }

    /**
     * Return whether `$host` is a literal IP in a private / link-local
     * range. Returns false for any hostname (we deliberately do NOT
     * resolve DNS here — that would defeat the loopback guarantee).
     *
     * Ranges treated as private:
     *   - IPv4 RFC1918:   10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16
     *   - IPv4 RFC3927:   169.254.0.0/16  (link-local)
     *   - IPv4 loopback:  127.0.0.0/8
     *   - IPv6 RFC4193:   fc00::/7        (unique local)
     *   - IPv6 RFC4291:   fe80::/10       (link-local)
     *   - IPv6 loopback:  ::1
     */
    private static function isPrivateAddress(string $host): bool
    {
        // PHP's filter_var with FLAG_NO_PRIV_RANGE | FLAG_NO_RES_RANGE
        // returns false for private / reserved addresses. We invert
        // that: if it fails the public/global validation it's
        // (private | reserved | loopback | link-local), all of which
        // we treat as "safe to probe".
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $isPublic = filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return $isPublic === false;
    }
}
