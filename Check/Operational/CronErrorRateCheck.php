<?php

/**
 * IronCart_Scan — IC-042 cron error rate.
 *
 * Looks at the most recent 100 entries in `cron_schedule` and flags when the
 * proportion that ended in `error` or `missed` exceeds 5%. A high error rate
 * usually means a failing third-party module, a misconfigured queue consumer,
 * or a host running out of resources — all of which can leave security tasks
 * unrun.
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
 * IC-042 — flag cron schedules with > 5% error/missed rate in last 100 entries.
 */
class CronErrorRateCheck implements CheckInterface
{
    public const ID = 'IC-042';

    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-042';

    /** Sample size we inspect for error rate. */
    public const SAMPLE_SIZE = 100;

    /** Fail percentage threshold (5.0 = 5%). */
    public const THRESHOLD_PCT = 5.0;

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
        $collection->setOrder('schedule_id', 'DESC');
        $collection->setPageSize(self::SAMPLE_SIZE);
        $collection->setCurPage(1);

        $total = 0;
        $failed = 0;
        $failedStatuses = [Schedule::STATUS_ERROR, Schedule::STATUS_MISSED];
        $byStatus = [
            Schedule::STATUS_SUCCESS => 0,
            Schedule::STATUS_PENDING => 0,
            Schedule::STATUS_RUNNING => 0,
            Schedule::STATUS_ERROR => 0,
            Schedule::STATUS_MISSED => 0,
        ];

        foreach ($collection as $schedule) {
            /** @var Schedule $schedule */
            $status = (string) $schedule->getStatus();
            $total++;
            if (!isset($byStatus[$status])) {
                $byStatus[$status] = 0;
            }
            $byStatus[$status]++;
            if (in_array($status, $failedStatuses, true)) {
                $failed++;
            }
        }

        if ($total === 0) {
            return [];
        }

        $rate = ($failed / $total) * 100.0;
        if ($rate <= self::THRESHOLD_PCT) {
            return [];
        }

        return [
            Finding::make(
                id: self::ID,
                title: sprintf(
                    'Cron error rate is %.1f%% in last %d entries (threshold %.1f%%)',
                    $rate,
                    $total,
                    self::THRESHOLD_PCT
                ),
                severity: Severity::MEDIUM,
                evidence: [
                    'sample_size' => $total,
                    'failed' => $failed,
                    'failed_pct' => round($rate, 2),
                    'threshold_pct' => self::THRESHOLD_PCT,
                    'by_status' => $byStatus,
                ],
                remediationUrl: self::REMEDIATION_URL
            ),
        ];
    }
}
