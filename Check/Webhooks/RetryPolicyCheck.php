<?php

/**
 * IronCart_Scan — IC-092: webhook retry policy is too aggressive.
 *
 * Flags subscriptions whose retry policy is configured in a way that lets
 * Magento become an amplification surface against an attacker-controlled
 * endpoint:
 *
 *   - `max_retries` > 100 — the store will keep retrying forever or close
 *     to it; combined with a destination URL the attacker controls (or has
 *     poisoned with a redirect chain) the store ends up issuing many more
 *     outbound requests than the merchant intends.
 *   - `retry_backoff` < 5 seconds — the retries arrive so quickly that
 *     they behave like a DoS against either the destination or, worse,
 *     against the Magento host itself when retries pile up in the queue.
 *
 * Severity is MEDIUM: misconfigured retry knobs rarely cause direct
 * compromise but they significantly raise the blast radius of an
 * unrelated webhook misconfiguration (see IC-090, IC-093).
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
 * IC-092 — webhook retry policy is configured outside safe bounds.
 */
class RetryPolicyCheck implements CheckInterface
{
    public const ID = 'IC-092';

    public const MAX_RETRIES_THRESHOLD = 100;

    public const MIN_RETRY_BACKOFF_SECONDS = 5;

    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-092';

    public function __construct(
        private readonly WebhookSubscriptionReader $reader
    ) {
    }

    public function run(): array
    {
        if (!$this->reader->isWebhooksModulePresent()) {
            return [];
        }

        $offenders = [];
        foreach ($this->reader->all() as $row) {
            $reasons = $this->reasonsFor($row);
            if ($reasons === []) {
                continue;
            }
            $offenders[] = [
                'subscription_id' => $row['subscription_id'],
                'name' => $row['name'],
                'max_retries' => $row['max_retries'],
                'retry_backoff' => $row['retry_backoff'],
                'reasons' => $reasons,
            ];
        }

        if ($offenders === []) {
            return [];
        }

        return [
            Finding::make(
                id: self::ID,
                title: sprintf(
                    '%d webhook subscription(s) have an unsafe retry policy',
                    count($offenders)
                ),
                severity: Severity::MEDIUM,
                evidence: [
                    'max_retries_threshold' => self::MAX_RETRIES_THRESHOLD,
                    'min_retry_backoff_seconds' => self::MIN_RETRY_BACKOFF_SECONDS,
                    'subscriptions' => $offenders,
                ],
                remediationUrl: self::REMEDIATION_URL
            ),
        ];
    }

    /**
     * Return the list of reasons a subscription's retry policy fails.
     * A subscription with both knobs out-of-bounds yields both reasons.
     *
     * `retry_backoff = 0` is treated as unset (the reader normalises NULL
     * to 0) and is not flagged — it means the merchant left the field at
     * the Magento default, not that they explicitly chose a sub-second
     * backoff. Same for `max_retries`.
     *
     * @param array{max_retries:int, retry_backoff:int} $row
     * @return list<string>
     */
    private function reasonsFor(array $row): array
    {
        $reasons = [];
        if ($row['max_retries'] > self::MAX_RETRIES_THRESHOLD) {
            $reasons[] = sprintf(
                'max_retries=%d exceeds threshold %d',
                $row['max_retries'],
                self::MAX_RETRIES_THRESHOLD
            );
        }
        if ($row['retry_backoff'] > 0 && $row['retry_backoff'] < self::MIN_RETRY_BACKOFF_SECONDS) {
            $reasons[] = sprintf(
                'retry_backoff=%ds is shorter than minimum %ds',
                $row['retry_backoff'],
                self::MIN_RETRY_BACKOFF_SECONDS
            );
        }
        return $reasons;
    }
}
