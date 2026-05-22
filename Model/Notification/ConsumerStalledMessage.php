<?php

/**
 * IronCart_Scan — admin notice for a stalled scan-run consumer.
 *
 * Issue #92: Run-Scan-Now leaves rows permanently in `queued` on installs
 * where neither a long-running `bin/magento queue:consumers:start
 * ironcartScanRunConsumer` supervisor nor Magento's own cron (which drives
 * the module's `ironcart_scan_consumer_drain` job, see {@see
 * \IronCart\Scan\Cron\DrainScanConsumer}) is actually picking the consumer
 * up. The queue pipeline itself is correct — this is an operator-detection
 * notice that fires from Magento's admin notice list.
 *
 * The message:
 *   - is read-only (queries `ironcart_scan_run` via the standard
 *     resource-model connection, no writes);
 *   - has a stable identity so Magento dedups it across requests;
 *   - severity MAJOR (the scans-listing render is misleading until the
 *     operator either starts the consumer or disables Run-Scan-Now).
 *
 * The actual stuck-row decision is delegated to
 * {@see ConsumerStalledPredicate}, which is Magento-free and unit-tested
 * under `Test/Unit/Report/ConsumerStalledPredicateTest`. This class is
 * the Magento-side glue: it pulls candidate rows out of the DB, reads
 * the threshold from `ironcart_scan/runtime/consumer_alert_threshold_seconds`,
 * and feeds the predicate.
 *
 * Wired into `Magento\Framework\Notification\MessageList` via
 * `etc/adminhtml/di.xml` so the message appears in the admin notice bell
 * on every adminhtml request — including the Scans listing index, which
 * is where the customer in #92 hits the bug.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Model\Notification;

use IronCart\Scan\Model\ResourceModel\ScanRun as ScanRunResource;
use IronCart\Scan\Model\ResourceModel\ScanRun\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Notification\MessageInterface;
use Throwable;

/**
 * Admin notice that fires when the ironcartScanRunConsumer is not
 * draining the queue.
 */
class ConsumerStalledMessage implements MessageInterface
{
    /**
     * Config path holding the operator-tunable threshold (seconds).
     * Mirrored in `etc/config.xml` with the default value baked in by
     * {@see ConsumerStalledPredicate::DEFAULT_THRESHOLD_SECONDS}.
     */
    public const CONFIG_PATH_THRESHOLD = 'ironcart_scan/runtime/consumer_alert_threshold_seconds';

    /**
     * Stable identity Magento uses for dedup. Bumping this string is the
     * supported way to force the notice to re-show after a previously-
     * dismissed version (we have no migration story for that today, but
     * naming the version into the identity keeps the door open).
     */
    private const IDENTITY = 'ironcart_scan_consumer_stalled_v1';

    /**
     * Consumer handle from `etc/queue_consumer.xml`. Named in the notice
     * text so the operator can copy-paste it into the queue:consumers:start
     * invocation without cross-referencing the README.
     */
    private const CONSUMER_NAME = 'ironcartScanRunConsumer';

    public function __construct(
        private readonly CollectionFactory $scanRunCollectionFactory,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getIdentity(): string
    {
        return self::IDENTITY;
    }

    /**
     * Predicate: should the notice be shown right now?
     *
     * Returns true only when there is at least one `ironcart_scan_run`
     * row whose `status = queued` and whose `started_at` is older than
     * the configured threshold. Reads are scoped to a thin
     * `SELECT status, started_at FROM ironcart_scan_run WHERE status = 'queued'`
     * via the standard collection — no LIMIT trickery, because in the
     * stuck state there are at most a handful of rows (operators don't
     * spam Run-Scan-Now while it's broken), and the predicate short-
     * circuits the moment any row qualifies.
     *
     * Any exception — DB unavailable, table missing on a partially-
     * installed module, etc. — is swallowed and reported as "not
     * stalled" so the admin notice list cannot crash an unrelated admin
     * page render. The trade-off: a transient DB failure suppresses
     * this notice for one request cycle, which is acceptable because
     * Magento re-runs `isDisplayed()` on every adminhtml render.
     */
    public function isDisplayed(): bool
    {
        try {
            $collection = $this->scanRunCollectionFactory->create();
            $collection->addFieldToFilter('status', ConsumerStalledPredicate::STATUS_QUEUED);
            $collection->addFieldToSelect(['status', 'started_at']);

            $rows = [];
            foreach ($collection as $item) {
                /** @var \IronCart\Scan\Model\ScanRun $item */
                $rows[] = [
                    'status'     => (string)$item->getStatus(),
                    'started_at' => $item->getStartedAt(),
                ];
            }
            unset($collection);

            return ConsumerStalledPredicate::hasStalledRow(
                $rows,
                $this->resolveThresholdSeconds(),
                time()
            );
        } catch (Throwable) {
            // Fail safe — never let a notice predicate take down the
            // admin chrome. See class doc on the trade-off.
            return false;
        }
    }

    public function getText(): string
    {
        // Plain string. Magento wraps the value in its own escaping
        // before rendering. The text names the consumer and the two
        // remediation paths an operator can take — verify Magento's
        // own cron is healthy (which drives the module's own drain
        // job, `ironcart_scan_consumer_drain`, every minute), or run
        // a dedicated foreground supervisor — so the operator does
        // not need to round-trip to the README to act on it. The
        // README link is included for the full walkthrough.
        //
        // The legacy `cron_consumers_runner` env.php edit is
        // intentionally not mentioned: post-#143 the module owns its
        // own drain via cron, and the README at "Running scans
        // asynchronously" explicitly retires that recommendation.
        // See issue #158.
        return sprintf(
            'IronCart_Scan: scans are being enqueued but the message-queue consumer "%s" is not draining them. '
            . 'Until the consumer is running, every "Run Scan Now" click leaves a row stuck at QUEUED. '
            . 'Verify Magento\'s cron is running (bin/magento cron:install) — the module ships its own cron job '
            . '"ironcart_scan_consumer_drain" that drives %s every minute when cron is healthy. '
            . 'If you prefer a dedicated supervisor, start it as a long-running worker instead '
            . '(bin/magento queue:consumers:start %s). '
            . 'See https://github.com/IronCartLabs/IronCartM2#running-scans-asynchronously for the operator walkthrough.',
            self::CONSUMER_NAME,
            self::CONSUMER_NAME,
            self::CONSUMER_NAME
        );
    }

    public function getSeverity(): int
    {
        // MAJOR per AC. The admin grid is actively misleading while
        // this condition holds (queued rows render as all-zero severity
        // circles with empty `Finished` columns); CRITICAL is reserved
        // for "module install broken" — the queue isn't broken here,
        // just unattended.
        return MessageInterface::SEVERITY_MAJOR;
    }

    /**
     * Resolve the threshold seconds. Always returns a non-negative int;
     * any malformed value silently falls back to the predicate's
     * built-in default (which matches the value in `etc/config.xml`,
     * so the only way to hit this fallback is a corrupted DB config
     * row).
     */
    private function resolveThresholdSeconds(): int
    {
        $raw = $this->scopeConfig->getValue(self::CONFIG_PATH_THRESHOLD);
        if ($raw === null || $raw === '') {
            return ConsumerStalledPredicate::DEFAULT_THRESHOLD_SECONDS;
        }
        if (is_int($raw)) {
            return $raw >= 0 ? $raw : ConsumerStalledPredicate::DEFAULT_THRESHOLD_SECONDS;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            return (int)$raw;
        }
        return ConsumerStalledPredicate::DEFAULT_THRESHOLD_SECONDS;
    }

    /**
     * Resource-model class anchor for the collection factory's
     * connection — exposed to aid debug only, never called from the
     * production path. Lets static-analysis tooling see the binding
     * between this message and the `ironcart_scan_run` table without
     * tracing through Magento's autogenerated factory machinery.
     *
     * @internal
     */
    public static function backingResourceClass(): string
    {
        return ScanRunResource::class;
    }
}
