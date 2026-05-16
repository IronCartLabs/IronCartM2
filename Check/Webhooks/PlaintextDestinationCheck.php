<?php

/**
 * IronCart_Scan — IC-090: webhook destination over plaintext HTTP.
 *
 * Flags any Adobe Commerce Webhooks subscription whose destination URL uses
 * scheme `http://` instead of `https://`. A plaintext webhook destination
 * lets a network-positioned attacker intercept order events, customer-state
 * changes, and any HMAC signature on the wire — and, more practically, an
 * attacker who controls a hop between the merchant and the destination can
 * replay/modify the payload to defraud or extract PII.
 *
 * Reads from local DB only via {@see WebhookSubscriptionReader}. The
 * destination URL is parsed and inspected; the check never opens a
 * connection to it. Probing live webhook endpoints could trigger
 * production handlers and is explicitly out of scope for the module —
 * see issue #49.
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
 * IC-090 — webhook destination URL uses plaintext HTTP.
 */
class PlaintextDestinationCheck implements CheckInterface
{
    public const ID = 'IC-090';

    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-090';

    public function __construct(
        private readonly WebhookSubscriptionReader $reader
    ) {
    }

    public function run(): array
    {
        if (!$this->reader->isWebhooksModulePresent()) {
            // Adobe Commerce Webhooks not installed — silent no-op. The v0
            // schema_version stays stable; we do not emit a "module absent"
            // info finding.
            return [];
        }

        $plaintext = [];
        foreach ($this->reader->all() as $row) {
            $url = $row['destination_url'];
            if ($url === '') {
                continue;
            }
            if (!$this->isPlaintextHttp($url)) {
                continue;
            }
            $plaintext[] = [
                'subscription_id' => $row['subscription_id'],
                'name' => $row['name'],
                'destination_url' => $url,
            ];
        }

        if ($plaintext === []) {
            return [];
        }

        return [
            Finding::make(
                id: self::ID,
                title: sprintf(
                    '%d webhook subscription(s) deliver over plaintext HTTP',
                    count($plaintext)
                ),
                severity: Severity::HIGH,
                evidence: [
                    'subscriptions' => $plaintext,
                ],
                remediationUrl: self::REMEDIATION_URL
            ),
        ];
    }

    /**
     * Return whether the URL is HTTP (not HTTPS). The scheme check is
     * case-insensitive (`HTTP://` is just as bad). A template URL like
     * `{$base_url}/hook` parses with no scheme and is treated as
     * not-plaintext so we don't emit a noisy finding for placeholders that
     * are filled in at delivery time — IC-093 likewise skips those.
     */
    private function isPlaintextHttp(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || $scheme === '') {
            return false;
        }
        return strtolower($scheme) === 'http';
    }
}
