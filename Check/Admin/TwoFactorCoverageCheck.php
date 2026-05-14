<?php

/**
 * IronCart_Scan — IC-012: 2FA coverage.
 *
 * Reports the share of active admin users with 2FA configured. Magento 2.4+
 * requires the bundled `Magento_TwoFactorAuth` module to be enabled, but
 * per-user enrolment is enforced at first login — so a store can pass the
 * module-presence check while having half its admins unenrolled.
 *
 * Severity ladder:
 *   - `critical` when coverage is below 100%, **and** at least one of the
 *     unenrolled accounts has any admin role assigned (i.e. privileged).
 *   - `high` when only unprivileged (no-role) accounts lack 2FA.
 *   - no finding emitted when coverage is 100% (the all-clear case).
 *
 * Reads via the user collection — no raw SQL. Username inclusion is gated on
 * `--include-usernames` per the IronCartM2 PII policy.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Admin;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\ScanSession;
use IronCart\Scan\Report\Severity;
use Magento\User\Model\ResourceModel\User\CollectionFactory;

/**
 * IC-012: 2FA coverage for active admin users.
 */
class TwoFactorCoverageCheck implements CheckInterface
{
    public const ID = 'IC-012';

    public function __construct(
        private readonly CollectionFactory $userCollectionFactory,
        private readonly ScanSession $session,
    ) {
    }

    public function run(): array
    {
        $collection = $this->userCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);

        $includeUsernames = $this->session->includeUsernames();

        $total = 0;
        $enrolled = 0;
        $unenrolledPrivileged = [];
        $unenrolledUnprivileged = [];

        /** @var \Magento\User\Model\User $user */
        foreach ($collection as $user) {
            $total++;

            $userId = (int) $user->getId();
            $username = (string) ($user->getData('username') ?? '');

            if ($this->userIsEnrolled($user)) {
                $enrolled++;
                continue;
            }

            $bucket = $this->userIsPrivileged($user)
                ? 'unenrolledPrivileged'
                : 'unenrolledUnprivileged';

            $record = [
                'user_id' => $userId,
            ];
            if ($includeUsernames && $username !== '') {
                $record['username'] = $username;
            }
            ${$bucket}[] = $record;
        }

        if ($total === 0) {
            return [];
        }

        $unenrolled = $total - $enrolled;
        if ($unenrolled === 0) {
            return [];
        }

        $privilegedGap = count($unenrolledPrivileged) > 0;
        $severity = $privilegedGap ? Severity::CRITICAL : Severity::HIGH;

        return [[
            'id' => self::ID,
            'title' => sprintf(
                '2FA not configured for %d of %d active admin user(s)',
                $unenrolled,
                $total
            ),
            'severity' => $severity,
            'evidence' => [
                'total_active' => $total,
                'enrolled' => $enrolled,
                'unenrolled' => $unenrolled,
                'coverage_pct' => $total > 0
                    ? (int) round(($enrolled / $total) * 100)
                    : 0,
                'unenrolled_privileged' => $unenrolledPrivileged,
                'unenrolled_unprivileged' => $unenrolledUnprivileged,
            ],
            'remediation_url' => 'https://ironcart.dev/docs/checks/IC-012',
        ]];
    }

    /**
     * A user is considered 2FA-enrolled when the bundled TwoFactorAuth module
     * has recorded at least one provider for them. The module stores this on
     * the user row as `tfa_providers_codes` (Magento >=2.4.6) or in the legacy
     * `twofactorauth_provider_data` column on older patch releases; we treat
     * either as a positive signal.
     */
    private function userIsEnrolled(\Magento\Framework\DataObject $user): bool
    {
        $providers = $user->getData('tfa_providers_codes');
        if (is_string($providers) && $providers !== '' && $providers !== '[]') {
            return true;
        }

        $legacy = $user->getData('twofactorauth_provider_data');
        if (is_string($legacy) && $legacy !== '') {
            return true;
        }

        return false;
    }

    /**
     * Treat any active admin user with at least one role assignment as
     * privileged. (Magento's admin user model exposes the joined role data
     * via `getRole()`; we fall back to the raw `role_id` column for tests
     * that do not stub the role lookup.)
     */
    private function userIsPrivileged(\Magento\Framework\DataObject $user): bool
    {
        if (method_exists($user, 'getRole')) {
            $role = $user->getRole();
            if (is_object($role) && method_exists($role, 'getId') && (int) $role->getId() > 0) {
                return true;
            }
        }

        $roleId = $user->getData('role_id');
        return is_numeric($roleId) && (int) $roleId > 0;
    }
}
