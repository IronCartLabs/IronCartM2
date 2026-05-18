<?php

/**
 * IronCart_Scan — Adobe Commerce Webhooks subscription reader.
 *
 * Tiny helper shared by every IC-09x webhook posture check. It does two
 * things — and only two:
 *
 *   1. Probe whether the optional `Magento_AdobeCommerceWebhooks` module is
 *      installed, using the same `class_exists()` + string-FQCN pattern that
 *      PR #35 / commit 1dfd9f1 introduced for `Magento_MysqlMq` in IC-043.
 *      Importing the Subscription collection factory at the top of this file
 *      would make `setup:di:compile` resolve the class eagerly via
 *      `ClassReader::getParameterClass()` and abort every `bin/magento`
 *      invocation on hosts where the module is absent (vanilla CE merchants
 *      who never opted into webhooks).
 *
 *   2. Normalise each subscription row into a plain associative array shaped
 *      for the IC-09x checks — `destination_url`, `signature_secret`,
 *      `max_retries`, `retry_backoff`, `subscription_id`, `name`. Each check
 *      then has a small read-only fixture surface and never reaches into the
 *      Magento model objects directly. This keeps the check classes free of
 *      `getData()` knowledge and makes their unit tests trivial.
 *
 * Read-only. No outbound network. No writes to the DB.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Webhooks;

use Magento\Framework\App\ObjectManager;

/**
 * Read normalised webhook subscriptions from Adobe Commerce Webhooks.
 *
 * Production callers leave the constructor parameter null — the reader
 * resolves the collection factory via ObjectManager when present and
 * returns an empty list when the module is absent. Unit tests inject a
 * duck-typed factory stub directly.
 */
class WebhookSubscriptionReader
{
    /**
     * FQCN of the Adobe Commerce Webhooks subscription collection factory.
     * Kept as a string so reflection / ClassReader never resolves it at DI
     * compile time. See class docblock for why this matters.
     */
    public const SUBSCRIPTION_FACTORY_FQCN =
        'Magento\\AdobeCommerceWebhooks\\Model\\ResourceModel\\Subscription\\CollectionFactory';

    /**
     * @param object|null $subscriptionCollectionFactory Test seam; production leaves null.
     */
    public function __construct(
        private readonly ?object $subscriptionCollectionFactory = null
    ) {
    }

    /**
     * Return whether the Adobe Commerce Webhooks module is present.
     *
     * Checks both the injected test seam (truthy = present) and the
     * `class_exists()` probe against the canonical FQCN. Callers use this
     * to distinguish "no findings because nothing to check" from "no
     * findings because the module is fine".
     */
    public function isWebhooksModulePresent(): bool
    {
        if ($this->subscriptionCollectionFactory !== null) {
            return true;
        }
        return class_exists(self::SUBSCRIPTION_FACTORY_FQCN);
    }

    /**
     * Return the normalised list of webhook subscriptions.
     *
     * Each entry has the shape:
     *   - subscription_id: string  (stable identifier from the subscription row)
     *   - name:            string  (human-readable subscription name, falls back to id)
     *   - destination_url: string  (URL the webhook POSTs to; may contain `{$var}` templates)
     *   - signature_secret: string (HMAC secret; empty string when missing/null)
     *   - max_retries:     int     (configured max retry count; 0 when unset)
     *   - retry_backoff:   int     (seconds between retries; 0 when unset)
     *
     * @return list<array{
     *     subscription_id:string,
     *     name:string,
     *     destination_url:string,
     *     signature_secret:string,
     *     max_retries:int,
     *     retry_backoff:int
     * }>
     */
    public function all(): array
    {
        $factory = $this->resolveFactory();
        if ($factory === null) {
            // Module absent — caller short-circuits to no findings.
            return [];
        }

        $collection = $factory->create();
        $rows = [];
        foreach ($collection as $subscription) {
            $rows[] = $this->normaliseRow($subscription);
        }
        return $rows;
    }

    /**
     * Resolve the subscription collection factory, mirroring the resolution
     * precedence used by IC-043: injected test seam first, then a
     * `class_exists()`-guarded ObjectManager lookup, then null.
     *
     * MEQP suppression — see #84. The `ObjectManager::getInstance()` call
     * below is a documented graceful-degradation seam for an optional
     * cross-module dependency (Adobe Commerce Webhooks). Constructor DI of
     * `\Magento\AdobeCommerceWebhooks\Model\ResourceModel\Subscription\CollectionFactory`
     * would make `setup:di:compile` resolve the class eagerly and abort on
     * hosts where the module is absent. Adobe's own modules use the same
     * pattern in the same situation (canonical example:
     * `Magento\AdvancedSearch\Model\Client\ClientResolver`). The PHPMD
     * suppression annotation + phpcs:ignore directive below are read by
     * Adobe's MEQP static analyser; do not remove without keeping the
     * graceful-degradation contract intact.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function resolveFactory(): ?object
    {
        if ($this->subscriptionCollectionFactory !== null) {
            return $this->subscriptionCollectionFactory;
        }

        if (!class_exists(self::SUBSCRIPTION_FACTORY_FQCN)) {
            return null;
        }

        // phpcs:ignore Magento2.PHP.AvoidObjectManager.FoundObjectManager
        return ObjectManager::getInstance()->get(self::SUBSCRIPTION_FACTORY_FQCN);
    }

    /**
     * Coerce a subscription model (or any duck-typed equivalent) into the
     * normalised array shape documented on {@see all()}.
     *
     * Reads via `getData()` when available — that's the canonical Magento
     * `AbstractModel` accessor — and falls back to `get<Studly>()` getters
     * for plain duck-typed stubs used by the unit tests.
     */
    private function normaliseRow(object $subscription): array
    {
        $id = $this->readField($subscription, 'subscription_id');
        $name = $this->readField($subscription, 'name');
        $url = $this->readField($subscription, 'destination_url');
        $secret = $this->readField($subscription, 'signature_secret');
        $maxRetries = $this->readField($subscription, 'max_retries');
        $retryBackoff = $this->readField($subscription, 'retry_backoff');

        $idStr = is_string($id) || is_int($id) ? (string) $id : '';
        $nameStr = is_string($name) && $name !== '' ? $name : $idStr;

        return [
            'subscription_id' => $idStr,
            'name' => $nameStr,
            'destination_url' => is_string($url) ? $url : '',
            'signature_secret' => is_string($secret) ? $secret : '',
            'max_retries' => is_numeric($maxRetries) ? (int) $maxRetries : 0,
            'retry_backoff' => is_numeric($retryBackoff) ? (int) $retryBackoff : 0,
        ];
    }

    /**
     * Read a column/field from a subscription, preferring `getData($field)`
     * (Magento AbstractModel) and falling back to a `get<Studly>()` accessor
     * for duck-typed test stubs.
     */
    private function readField(object $subscription, string $field): mixed
    {
        if (method_exists($subscription, 'getData')) {
            $value = $subscription->getData($field);
            if ($value !== null) {
                return $value;
            }
        }
        $getter = 'get' . str_replace('_', '', ucwords($field, '_'));
        if (method_exists($subscription, $getter)) {
            return $subscription->{$getter}();
        }
        return null;
    }
}
