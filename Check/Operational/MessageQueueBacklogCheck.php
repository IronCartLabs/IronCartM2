<?php

/**
 * IronCart_Scan — IC-043 message-queue backlog.
 *
 * Iterates the Magento MessageQueue topology and flags any queue whose depth
 * exceeds 10k messages. A runaway queue is rarely a security finding on its
 * own but it routinely correlates with broken consumers — and broken consumers
 * mean async security tasks (re-index after permission change, customer-data
 * scrub, etc.) silently never run.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Operational;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\Finding;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\ObjectManager;

/**
 * IC-043 — flag message-queue backlogs that exceed the depth threshold.
 *
 * Note on the missing `use Magento\MysqlMq\...\CollectionFactory` import: the
 * MysqlMq module is optional. It ships with Adobe Commerce and as an opt-in
 * Open Source install, but a vanilla CE 2.4.7-p5 box does not have it. If we
 * import the class or type-hint it on the constructor, Magento's
 * `ClassReader::getParameterClass()` resolves the type eagerly at DI compile
 * time — which means a missing `Magento_MysqlMq` aborts the whole
 * `setup:di:compile` (and therefore every `bin/magento` command, including
 * `setup:install`). Probing for the class lazily via `class_exists()` and a
 * string-based ObjectManager lookup is the canonical Magento pattern for
 * optional cross-module dependencies — see e.g. modules that probe for
 * `Magento_AdobeIms` the same way.
 */
class MessageQueueBacklogCheck implements CheckInterface
{
    public const ID = 'IC-043';

    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-043';

    /** FQCN of the MysqlMq queue collection factory; kept as a string so that
     *  reflection / ClassReader never resolves it at DI compile time. */
    private const MYSQLMQ_FACTORY_FQCN =
        'Magento\\MysqlMq\\Model\\ResourceModel\\Queue\\CollectionFactory';

    /** Depth threshold per queue. */
    public const DEPTH_THRESHOLD = 10000;

    /**
     * The constructor takes a generic `?object` rather than a typed
     * `?CollectionFactory` so DI reflection never touches the optional class.
     * Production callers should leave both parameters at their defaults; the
     * check resolves the factory itself via ObjectManager when present. The
     * parameters exist purely as a test seam — unit tests inject a duck-typed
     * factory stub directly.
     *
     * @param object|null $queueCollectionFactory Test seam; production leaves null.
     */
    public function __construct(
        private readonly ?object $queueCollectionFactory = null
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function run(): array
    {
        $factory = $this->resolveFactory();
        if ($factory === null) {
            // Magento_MysqlMq is not installed (vanilla CE, RabbitMQ-only
            // operator, etc.). v0 limits itself to read-only checks against
            // Magento's local DB; a v3+ check can add an explicit RabbitMQ
            // adapter. Returning no findings keeps the v0 `schema_version`
            // JSON shape stable — no new severity, no new finding ID.
            return [];
        }

        $collection = $factory->create();
        $over = [];

        foreach ($collection as $queue) {
            // The MysqlMq queue model exposes name + the related messages
            // collection via getMessages(). Use the collection's getSize() —
            // it issues a COUNT(*) under the hood and avoids hydrating the
            // payload column for thousands of rows.
            $name = method_exists($queue, 'getName') ? (string) $queue->getName() : '';
            if ($name === '') {
                continue;
            }

            $depth = 0;
            if (method_exists($queue, 'getMessages')) {
                $messages = $queue->getMessages();
                if (is_object($messages) && method_exists($messages, 'getSize')) {
                    $depth = (int) $messages->getSize();
                }
            }

            if ($depth > self::DEPTH_THRESHOLD) {
                $over[] = [
                    'queue' => $name,
                    'depth' => $depth,
                ];
            }
        }

        if ($over === []) {
            return [];
        }

        return [
            Finding::make(
                id: self::ID,
                title: sprintf(
                    '%d message queue(s) over the %d-message backlog threshold',
                    count($over),
                    self::DEPTH_THRESHOLD
                ),
                severity: Severity::MEDIUM,
                evidence: [
                    'threshold' => self::DEPTH_THRESHOLD,
                    'queues' => $over,
                ],
                remediationUrl: self::REMEDIATION_URL
            ),
        ];
    }

    /**
     * Resolve a queue collection factory if Magento_MysqlMq is installed.
     *
     * Precedence:
     *   1. A factory injected through the constructor (unit-test seam).
     *   2. ObjectManager lookup, *guarded* by `class_exists()` so the autoloader
     *      never tries to load the absent class.
     *   3. null — caller short-circuits to no findings.
     *
     * MEQP suppression — see #84. The `ObjectManager::getInstance()` call
     * below is a documented graceful-degradation seam for an optional
     * cross-module dependency (`Magento_MysqlMq`). Constructor DI of
     * `\Magento\MysqlMq\Model\ResourceModel\Queue\CollectionFactory` would
     * make `setup:di:compile` resolve the class eagerly and abort on hosts
     * where the module is absent (vanilla CE, RabbitMQ-only operators).
     * Adobe's own modules use the same pattern in the same situation
     * (canonical example:
     * `Magento\AdvancedSearch\Model\Client\ClientResolver`). The PHPMD
     * suppression annotation + phpcs:ignore directive below are read by
     * Adobe's MEQP static analyser; do not remove without keeping the
     * graceful-degradation contract intact.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function resolveFactory(): ?object
    {
        if ($this->queueCollectionFactory !== null) {
            return $this->queueCollectionFactory;
        }

        // `class_exists($fqcn, true)` will trigger autoload but Composer's
        // class map returns false cleanly when the module is absent — it does
        // not throw. This is the standard optional-dependency probe.
        if (!class_exists(self::MYSQLMQ_FACTORY_FQCN)) {
            return null;
        }

        // ObjectManager direct access is normally discouraged but is the
        // canonical pattern for *exactly this case*: an optional cross-module
        // dependency where the depending module must boot without it.
        // phpcs:ignore Magento2.PHP.AvoidObjectManager.FoundObjectManager
        return ObjectManager::getInstance()->get(self::MYSQLMQ_FACTORY_FQCN);
    }
}
