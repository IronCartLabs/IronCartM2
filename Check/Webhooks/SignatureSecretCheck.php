<?php

/**
 * IronCart_Scan — IC-091: webhook signature verification disabled.
 *
 * Flags any Adobe Commerce Webhooks subscription whose `signature_secret`
 * is empty or null. Without a signature secret, the receiving service has
 * no cryptographic way to verify that an incoming POST came from this
 * Magento store — anyone who learns the destination URL (a CDN log line,
 * a leaked nginx access log, a screenshot in a Jira ticket) can spoof
 * inbound events.
 *
 * Reads from local DB only via {@see WebhookSubscriptionReader}. No
 * outbound network. Silent no-op when the Adobe Commerce Webhooks module
 * is not installed.
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
 * IC-091 — webhook subscription is missing a signature secret.
 */
class SignatureSecretCheck implements CheckInterface
{
    public const ID = 'IC-091';

    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-091';

    public function __construct(
        private readonly WebhookSubscriptionReader $reader
    ) {
    }

    public function run(): array
    {
        if (!$this->reader->isWebhooksModulePresent()) {
            return [];
        }

        $unsigned = [];
        foreach ($this->reader->all() as $row) {
            // Empty-string is the normalised reading for both NULL and ''
            // — see WebhookSubscriptionReader::normaliseRow().
            if (trim($row['signature_secret']) !== '') {
                continue;
            }
            $unsigned[] = [
                'subscription_id' => $row['subscription_id'],
                'name' => $row['name'],
            ];
        }

        if ($unsigned === []) {
            return [];
        }

        return [
            Finding::make(
                id: self::ID,
                title: sprintf(
                    '%d webhook subscription(s) have no signature secret configured',
                    count($unsigned)
                ),
                severity: Severity::HIGH,
                evidence: [
                    'subscriptions' => $unsigned,
                ],
                remediationUrl: self::REMEDIATION_URL
            ),
        ];
    }
}
