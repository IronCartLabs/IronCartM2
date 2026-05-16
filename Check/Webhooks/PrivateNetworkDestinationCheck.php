<?php

/**
 * IronCart_Scan — IC-093: webhook destination resolves to a private network.
 *
 * Flags subscriptions whose destination URL resolves to an RFC1918 private
 * range (10/8, 172.16/12, 192.168/16), loopback (127/8, ::1), or link-local
 * (169.254/16, fe80::/10) address. The most common real-world cause isn't
 * malice — it's accidentally pointing the webhook back at the Magento
 * host itself (e.g. someone copies the public webhook URL into a staging
 * config and forgets to update it) or at an internal service that an
 * external partner is supposed to consume. Either way the merchant ends
 * up routing event payloads into the internal network in a way the
 * external partner can never see, and which a network-positioned attacker
 * may be able to redirect to a service of their choosing via DNS rebind.
 *
 * Severity is MEDIUM: rarely a direct compromise, but always a sign that
 * the webhook config does not match the operator's mental model.
 *
 * **No outbound network.** The check parses the URL, then resolves the
 * hostname using PHP's `dns_get_record()` with a hard 2-second timeout.
 * The timeout is implemented with stream/socket context defaults via the
 * `default_socket_timeout` ini directive scoped for the resolution call
 * — `dns_get_record()` does not accept an explicit timeout parameter
 * but the resolver inherits this value. On lookup failure or timeout
 * the check skips that subscription silently (no noisy "DNS unavailable"
 * finding).
 *
 * Subscriptions whose destination URL contains a template placeholder
 * such as `{$some_var}` are skipped entirely: the runtime value can only
 * be resolved at delivery time, and emitting a finding against the raw
 * template is misleading.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Webhooks;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\Finding;
use IronCart\Scan\Report\Severity;

/**
 * IC-093 — webhook destination resolves to RFC1918 / loopback / link-local.
 */
class PrivateNetworkDestinationCheck implements CheckInterface
{
    public const ID = 'IC-093';

    /** DNS lookup hard ceiling in seconds. The 2s figure tracks issue #49. */
    public const DNS_TIMEOUT_SECONDS = 2;

    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-093';

    /**
     * @param WebhookSubscriptionReader $reader
     * @param callable|null $resolver Optional resolver override (test seam).
     *     Receives `(string $hostname): list<string>` of IPv4/IPv6 addresses,
     *     or returns an empty list when resolution fails / times out.
     *     Defaults to {@see dnsLookup()} which wraps `dns_get_record()`.
     */
    public function __construct(
        private readonly WebhookSubscriptionReader $reader,
        private $resolver = null
    ) {
    }

    public function run(): array
    {
        if (!$this->reader->isWebhooksModulePresent()) {
            return [];
        }

        $offenders = [];
        foreach ($this->reader->all() as $row) {
            $url = $row['destination_url'];
            if ($url === '' || $this->containsTemplate($url)) {
                continue;
            }
            $host = parse_url($url, PHP_URL_HOST);
            if (!is_string($host) || $host === '') {
                continue;
            }

            $addresses = $this->resolve($host);
            if ($addresses === []) {
                // Resolution failed (NXDOMAIN, timeout, network unavailable).
                // Skip silently per issue #49 — we never emit "DNS broken"
                // as a security finding.
                continue;
            }

            $private = $this->privateAddresses($addresses);
            if ($private === []) {
                continue;
            }

            $offenders[] = [
                'subscription_id' => $row['subscription_id'],
                'name' => $row['name'],
                'hostname' => $host,
                'private_addresses' => $private,
            ];
        }

        if ($offenders === []) {
            return [];
        }

        return [
            Finding::make(
                id: self::ID,
                title: sprintf(
                    '%d webhook subscription(s) deliver to private-network addresses',
                    count($offenders)
                ),
                severity: Severity::MEDIUM,
                evidence: [
                    'dns_timeout_seconds' => self::DNS_TIMEOUT_SECONDS,
                    'subscriptions' => $offenders,
                ],
                remediationUrl: self::REMEDIATION_URL
            ),
        ];
    }

    /**
     * Resolve `$host` to a list of IPv4/IPv6 addresses. Uses the injected
     * test-seam resolver when present; otherwise falls back to
     * {@see dnsLookup()}.
     *
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        // Literal IP — no DNS needed. parse_url() returns bracketed IPv6
        // hosts as `[::1]`; strip the brackets before FILTER_VALIDATE_IP
        // so the literal-IP shortcut also covers IPv6 destinations.
        $unwrapped = (str_starts_with($host, '[') && str_ends_with($host, ']'))
            ? substr($host, 1, -1)
            : $host;
        if (filter_var($unwrapped, FILTER_VALIDATE_IP)) {
            return [$unwrapped];
        }
        if (is_callable($this->resolver)) {
            $result = ($this->resolver)($host);
            return is_array($result) ? array_values(array_filter($result, 'is_string')) : [];
        }
        return $this->dnsLookup($host);
    }

    /**
     * Resolve `$host` via `dns_get_record()` with a 2s socket timeout.
     *
     * `dns_get_record()` has no explicit timeout parameter; it inherits
     * the `default_socket_timeout` ini value for the underlying resolver
     * sockets. We snapshot-and-restore that ini value around the call so
     * we don't leak the lowered timeout to unrelated code.
     *
     * On any failure (resolver error, no records, timeout) returns an
     * empty list — callers treat that as "skip silently".
     *
     * @return list<string>
     */
    private function dnsLookup(string $host): array
    {
        $previousTimeout = ini_get('default_socket_timeout');
        @ini_set('default_socket_timeout', (string) self::DNS_TIMEOUT_SECONDS);
        try {
            // DNS_A + DNS_AAAA covers IPv4 and IPv6. We intentionally do
            // not chase CNAMEs ourselves — the resolver follows them
            // transparently and returns the final A/AAAA record.
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (!is_array($records)) {
                return [];
            }
            $addresses = [];
            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $addresses[] = $record['ip'];
                }
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
            return $addresses;
        } finally {
            if ($previousTimeout !== false) {
                @ini_set('default_socket_timeout', (string) $previousTimeout);
            }
        }
    }

    /**
     * Return whether the URL contains a `{$placeholder}` template fragment.
     * The Adobe Commerce Webhooks UI lets operators reference variables
     * in destination URLs that are resolved at delivery time; we cannot
     * meaningfully resolve them in a static scan, so subscriptions that
     * use them are out of scope for IC-093.
     */
    private function containsTemplate(string $url): bool
    {
        return str_contains($url, '{$') || str_contains($url, '{{');
    }

    /**
     * Filter a list of addresses down to those that fall in a private,
     * loopback, or link-local range.
     *
     * Uses `filter_var()` with `FILTER_FLAG_NO_PRIV_RANGE` and
     * `FILTER_FLAG_NO_RES_RANGE` — an address that fails validation under
     * those flags is, by definition, in a non-public range. We invert
     * the result so this method returns the private set.
     *
     * @param list<string> $addresses
     * @return list<string>
     */
    private function privateAddresses(array $addresses): array
    {
        $private = [];
        foreach ($addresses as $address) {
            if (!is_string($address) || $address === '') {
                continue;
            }
            // FILTER_VALIDATE_IP with the no-private + no-reserved flags
            // returns false for any private/loopback/link-local/multicast
            // address. We treat that as "private" for IC-093.
            $isPublic = filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
            if (!$isPublic) {
                $private[] = $address;
            }
        }
        return $private;
    }
}
