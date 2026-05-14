<?php

/**
 * IronCart_Scan — IC-013: weak/default password indicators.
 *
 * The scanner cannot (and must not) read password hashes — that's an explicit
 * v0 invariant. What it can do is surface a proxy signal: active admin users
 * whose recorded `password_changed` is older than 180 days. A long-lived
 * password is a strong correlate of weak/reused/default credentials in
 * Magento estates, and Adobe Commerce's own hardening guide lists rotation
 * cadence as the first lever.
 *
 * No password material of any kind is read or emitted. Severity is medium.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Admin;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\ScanSession;
use IronCart\Scan\Report\Severity;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\User\Model\ResourceModel\User\CollectionFactory;

/**
 * IC-013: long-lived admin passwords as a weak-credential proxy.
 */
class WeakPasswordIndicatorCheck implements CheckInterface
{
    public const ID = 'IC-013';

    public const STALE_THRESHOLD_DAYS = 180;

    public function __construct(
        private readonly CollectionFactory $userCollectionFactory,
        private readonly DateTime $dateTime,
        private readonly ScanSession $session,
    ) {
    }

    public function run(): array
    {
        $collection = $this->userCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);

        $now = $this->dateTime->gmtTimestamp();
        $thresholdSeconds = self::STALE_THRESHOLD_DAYS * 86400;
        $includeUsernames = $this->session->includeUsernames();

        $usernames = [];
        $staleCount = 0;
        $totalActive = 0;

        /** @var \Magento\User\Model\User $user */
        foreach ($collection as $user) {
            $totalActive++;

            $changed = $user->getData('password_changed');
            // Fallback to `created` if `password_changed` is not populated.
            if (!is_string($changed) || $changed === '') {
                $changed = $user->getData('created');
            }
            $changedTs = is_string($changed) && $changed !== ''
                ? strtotime($changed)
                : null;

            $isStale = ($changedTs === null || $changedTs === false)
                ? true
                : (($now - $changedTs) > $thresholdSeconds);

            if ($isStale) {
                $staleCount++;
                if ($includeUsernames) {
                    $username = $user->getData('username');
                    if (is_string($username) && $username !== '') {
                        $usernames[] = $username;
                    }
                }
            }
        }

        if ($staleCount === 0) {
            return [];
        }

        $evidence = [
            'stale_count' => $staleCount,
            'total_active' => $totalActive,
            'threshold_days' => self::STALE_THRESHOLD_DAYS,
        ];
        if ($includeUsernames) {
            $evidence['usernames'] = $usernames;
        }

        return [[
            'id' => self::ID,
            'title' => sprintf(
                '%d active admin user(s) have not rotated their password in over %d days',
                $staleCount,
                self::STALE_THRESHOLD_DAYS
            ),
            'severity' => Severity::MEDIUM,
            'evidence' => $evidence,
            'remediation_url' => 'https://ironcart.dev/docs/checks/IC-013',
        ]];
    }
}
