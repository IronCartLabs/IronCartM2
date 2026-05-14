<?php

/**
 * IronCart_Scan — IC-041 cron last-run age.
 *
 * The `default` cron group runs nearly every Magento housekeeping job that
 * matters for security: ACL cache invalidation, customer-session cleanup,
 * indexer scheduling, log rotation. If it hasn't executed in the last hour
 * something is badly broken on the box.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Operational;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\Finding;
use IronCart\Scan\Report\Severity;
use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory as CronCollectionFactory;
use Magento\Cron\Model\Schedule;

/**
 * IC-041 — flag `default` cron group when last execution is > N minutes stale.
 */
class CronLastRunCheck implements CheckInterface
{
    public const ID = 'IC-041';

    /** Group name to monitor — Magento's catch-all housekeeping group. */
    public const GROUP = 'default';

    /** Staleness threshold in seconds. */
    public const STALE_SECONDS = 3600;

    /** Number of recent successful rows we inspect. */
    public const SAMPLE_SIZE = 50;

    public function __construct(
        private readonly CronCollectionFactory $cronCollectionFactory
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function run(): array
    {
        $collection = $this->cronCollectionFactory->create();
        $collection->addFieldToFilter('status', Schedule::STATUS_SUCCESS);
        $collection->addFieldToFilter('executed_at', ['notnull' => true]);
        $collection->setOrder('executed_at', 'DESC');
        $collection->setPageSize(self::SAMPLE_SIZE);
        $collection->setCurPage(1);

        $latestForGroup = null;
        $latestJobCode = null;
        foreach ($collection as $schedule) {
            /** @var Schedule $schedule */
            $jobCode = (string) $schedule->getJobCode();
            if ($this->resolveGroupForJob($jobCode) !== self::GROUP) {
                continue;
            }

            $executedAt = (string) $schedule->getExecutedAt();
            $ts = $executedAt !== '' ? strtotime($executedAt) : false;
            if ($ts === false) {
                continue;
            }

            if ($latestForGroup === null || $ts > $latestForGroup) {
                $latestForGroup = $ts;
                $latestJobCode = $jobCode;
            }
        }

        $now = time();

        if ($latestForGroup === null) {
            return [
                Finding::make(
                    id: self::ID,
                    title: sprintf('No successful "%s" cron run found in cron_schedule', self::GROUP),
                    severity: Severity::HIGH,
                    evidence: [
                        'group' => self::GROUP,
                        'inspected_rows' => self::SAMPLE_SIZE,
                    ],
                    remediationUrl: 'https://developer.adobe.com/commerce/php/development/components/cron/'
                ),
            ];
        }

        $age = $now - $latestForGroup;
        if ($age <= self::STALE_SECONDS) {
            return [];
        }

        return [
            Finding::make(
                id: self::ID,
                title: sprintf(
                    '"%s" cron group has not run for %d minute(s)',
                    self::GROUP,
                    (int) floor($age / 60)
                ),
                severity: Severity::HIGH,
                evidence: [
                    'group' => self::GROUP,
                    'threshold_seconds' => self::STALE_SECONDS,
                    'last_executed_at' => date('Y-m-d\TH:i:s\Z', $latestForGroup),
                    'last_job_code' => $latestJobCode,
                    'age_seconds' => $age,
                ],
                remediationUrl: 'https://developer.adobe.com/commerce/php/development/components/cron/'
            ),
        ];
    }

    /**
     * Resolve which cron group owns a given job code.
     *
     * v0 keeps this deliberately permissive: the canonical group lookup lives
     * in `Magento\Cron\Model\Config\Data`, but pulling it in via DI here would
     * conflate IC-041 with IC-042 + IC-043. Instead we inspect Magento's
     * standard built-in jobs and fall back to assuming `default`, which is
     * accurate for the overwhelming majority of installs.
     *
     * @internal
     */
    private function resolveGroupForJob(string $jobCode): string
    {
        // Conservative allow-list of non-`default` groups we know about. If a
        // job code starts with one of these prefixes, classify it accordingly.
        $prefixes = [
            'staging_' => 'staging',
            'consumers_' => 'consumers',
            'index_' => 'index',
        ];
        foreach ($prefixes as $prefix => $group) {
            if (str_starts_with($jobCode, $prefix)) {
                return $group;
            }
        }

        return self::GROUP;
    }
}
