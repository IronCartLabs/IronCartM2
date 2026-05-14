<?php

/**
 * IronCart_Scan — IC-011: admin user inventory.
 *
 * Counts active admin users whose last login is older than 90 days. Stale
 * accounts are a common foothold during incident response: they are real
 * credentials with full ACL but no one watching them. Severity is medium.
 *
 * Reads admin users via `\Magento\User\Model\ResourceModel\User\CollectionFactory`
 * — no raw SQL, no password hashes, no email addresses. Usernames are only
 * included in the evidence payload when the operator explicitly opts in via
 * the `--include-usernames` flag on `bin/magento ironcart:scan`, signalled
 * to checks through the DI-shared {@see ScanSession}.
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
 * IC-011: stale active admin accounts.
 */
class AdminUserInventoryCheck implements CheckInterface
{
    public const ID = 'IC-011';

    public const STALE_THRESHOLD_DAYS = 90;

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

        $staleUsernames = [];
        $staleCount = 0;
        $totalActive = 0;

        /** @var \Magento\User\Model\User $user */
        foreach ($collection as $user) {
            $totalActive++;
            $logged = $user->getData('logdate');
            // A never-logged-in active account is also stale.
            $loggedTs = is_string($logged) && $logged !== ''
                ? strtotime($logged)
                : null;

            $isStale = ($loggedTs === null || $loggedTs === false)
                ? true
                : (($now - $loggedTs) > $thresholdSeconds);

            if ($isStale) {
                $staleCount++;
                if ($includeUsernames) {
                    $username = $user->getData('username');
                    if (is_string($username) && $username !== '') {
                        $staleUsernames[] = $username;
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
            $evidence['usernames'] = $staleUsernames;
        }

        return [[
            'id' => self::ID,
            'title' => sprintf(
                '%d active admin user(s) have not logged in for over %d days',
                $staleCount,
                self::STALE_THRESHOLD_DAYS
            ),
            'severity' => Severity::MEDIUM,
            'evidence' => $evidence,
            'remediation_url' => 'https://ironcart.dev/docs/checks/IC-011',
        ]];
    }
}
