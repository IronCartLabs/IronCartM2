<?php

/**
 * IronCart_Scan — IC-014: recent admin-actions audit (Recon 7.3).
 *
 * Surfaces suspicious admin behaviour the outside-the-store scan can't see by
 * reading the last 24 hours of activity from Magento's admin tables:
 *
 *   - `admin_user`              — `created` / `modified` columns for newly
 *                                  created admin users and post-creation row
 *                                  edits (used here as a proxy for role
 *                                  assignment / permission changes, since the
 *                                  Magento authorization tables themselves
 *                                  carry no timestamps).
 *   - `admin_passwords`         — `last_updated` column for password resets.
 *   - `admin_user_session`      — `updated_at` / `ip` columns for live login
 *                                  activity and the "logged in outside the
 *                                  operator-configured business-hours window"
 *                                  signal.
 *
 * PII handling. The merchant's admin usernames and login IPs are PII. The
 * upload payload only ever carries:
 *
 *   - SHA-256 hashes of usernames (truncated to the first 16 hex chars — long
 *     enough to compare across runs, short enough to be useless for re-id
 *     against any external list); and
 *   - `/24`-truncated IPv4 prefixes (or `/48` IPv6) so two logins from the
 *     same building register as the same prefix without leaking the operator
 *     IP itself.
 *
 * The full plaintext username / IP is only included when the operator
 * explicitly opts in via `--include-usernames` on `bin/magento ironcart:scan`,
 * signalled through the DI-shared {@see ScanSession} — same opt-in this
 * module already uses for IC-011/IC-012/IC-013.
 *
 * Business hours. The "login outside business hours" sub-check is suppressed
 * (zero findings) until the operator opts in by configuring
 * `ironcart_scan/admin_audit/business_hours_start` and
 * `ironcart_scan/admin_audit/business_hours_end` (24-hour ints, store-server
 * local time). With both unset the check defaults to 24/7 = nothing flagged.
 *
 * Read-only. No raw SQL. The two Magento tables that lack a published
 * collection factory (`admin_passwords`, `admin_user_session`) are queried
 * through the framework's `ResourceConnection` + `Select` builder — the same
 * idiom Magento itself uses in `Magento\Security` and `Magento\User`. No
 * writes, no DDL, no outbound network.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\AdminAudit;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\Finding;
use IronCart\Scan\Check\ScanSession;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\User\Model\ResourceModel\User\CollectionFactory;

/**
 * IC-014 — recent admin-actions audit (last 24h).
 */
class RecentActionsCheck implements CheckInterface
{
    public const ID = 'IC-014';

    /** Lookback window in seconds. */
    public const LOOKBACK_SECONDS = 86400;

    /**
     * Truncation length for SHA-256 username hashes carried in the evidence
     * payload. 16 hex chars = 64 bits of collision resistance — comfortably
     * unique inside a single merchant's admin user set and useless as an
     * external re-id key.
     */
    public const HASH_PREFIX_LEN = 16;

    /** Admin config path: configured business-hours window start hour (0–23). */
    public const CONFIG_BUSINESS_HOURS_START = 'admin/security/business_hours_start';

    /** Admin config path: configured business-hours window end hour (0–23). */
    public const CONFIG_BUSINESS_HOURS_END = 'admin/security/business_hours_end';

    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-014';

    public function __construct(
        private readonly CollectionFactory $userCollectionFactory,
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DateTime $dateTime,
        private readonly ScanSession $session,
    ) {
    }

    public function run(): array
    {
        $now = $this->dateTime->gmtTimestamp();
        $since = $now - self::LOOKBACK_SECONDS;
        $sinceSql = gmdate('Y-m-d H:i:s', $since);
        $includePlaintext = $this->session->includeUsernames();

        $findings = [];

        $newUsers = $this->newAdminUsers($sinceSql, $includePlaintext);
        if ($newUsers['count'] > 0) {
            $findings[] = Finding::make(
                id: self::ID . '.new-admin',
                title: sprintf(
                    '%d new admin user(s) created in the last 24 hours',
                    $newUsers['count']
                ),
                severity: Severity::HIGH,
                evidence: $newUsers['evidence'],
                remediationUrl: self::REMEDIATION_URL,
            );
        }

        $modifiedUsers = $this->recentlyModifiedAdminUsers($sinceSql, $includePlaintext);
        if ($modifiedUsers['count'] > 0) {
            $findings[] = Finding::make(
                id: self::ID . '.role-change',
                title: sprintf(
                    '%d admin user row(s) modified in the last 24 hours (possible role / permission change)',
                    $modifiedUsers['count']
                ),
                severity: Severity::MEDIUM,
                evidence: $modifiedUsers['evidence'],
                remediationUrl: self::REMEDIATION_URL,
            );
        }

        $passwordResets = $this->recentPasswordResets($since, $includePlaintext);
        if ($passwordResets['count'] > 0) {
            $findings[] = Finding::make(
                id: self::ID . '.password-reset',
                title: sprintf(
                    '%d admin password reset(s) in the last 24 hours',
                    $passwordResets['count']
                ),
                severity: Severity::MEDIUM,
                evidence: $passwordResets['evidence'],
                remediationUrl: self::REMEDIATION_URL,
            );
        }

        $loginIps = $this->recentLoginIpPrefixes($sinceSql);
        if ($loginIps['count'] > 0) {
            $findings[] = Finding::make(
                id: self::ID . '.login-ips',
                title: sprintf(
                    'Admin logins in the last 24 hours came from %d distinct IP prefix(es)',
                    $loginIps['count']
                ),
                severity: Severity::INFO,
                evidence: $loginIps['evidence'],
                remediationUrl: self::REMEDIATION_URL,
            );
        }

        $offHours = $this->offHoursLogins($sinceSql, $includePlaintext);
        if ($offHours['count'] > 0) {
            $findings[] = Finding::make(
                id: self::ID . '.off-hours',
                title: sprintf(
                    '%d admin login(s) in the last 24 hours occurred outside the configured business-hours window',
                    $offHours['count']
                ),
                severity: Severity::HIGH,
                evidence: $offHours['evidence'],
                remediationUrl: self::REMEDIATION_URL,
            );
        }

        return $findings;
    }

    /**
     * New admin users — `admin_user.created` >= 24h ago.
     *
     * @return array{count:int, evidence:array<string,mixed>}
     */
    private function newAdminUsers(string $sinceSql, bool $includePlaintext): array
    {
        $collection = $this->userCollectionFactory->create();
        $collection->addFieldToFilter('created', ['gteq' => $sinceSql]);

        $count = 0;
        $hashes = [];
        $plaintext = [];

        /** @var \Magento\User\Model\User $user */
        foreach ($collection as $user) {
            $username = (string) ($user->getData('username') ?? '');
            if ($username === '') {
                continue;
            }
            $count++;
            $hashes[] = $this->hashUsername($username);
            if ($includePlaintext) {
                $plaintext[] = $username;
            }
        }

        $evidence = [
            'count' => $count,
            'lookback_seconds' => self::LOOKBACK_SECONDS,
            'username_hashes' => $hashes,
        ];
        if ($includePlaintext) {
            $evidence['usernames'] = $plaintext;
        }

        return ['count' => $count, 'evidence' => $evidence];
    }

    /**
     * Admin users whose `modified` timestamp is in the lookback window AND
     * whose `created` timestamp is older than the lookback window — i.e. an
     * existing row was edited recently. We treat this as a coarse proxy for
     * "role assignment changed" because Magento's authorization tables
     * (`authorization_rule`, `authorization_role`) do not carry timestamps,
     * so a true role-diff requires snapshotting between runs (out of scope
     * for v0). False positives expected — surfaces as MEDIUM, not HIGH.
     *
     * @return array{count:int, evidence:array<string,mixed>}
     */
    private function recentlyModifiedAdminUsers(string $sinceSql, bool $includePlaintext): array
    {
        $collection = $this->userCollectionFactory->create();
        $collection->addFieldToFilter('modified', ['gteq' => $sinceSql]);
        $collection->addFieldToFilter('created', ['lt' => $sinceSql]);

        $count = 0;
        $hashes = [];
        $plaintext = [];

        /** @var \Magento\User\Model\User $user */
        foreach ($collection as $user) {
            $username = (string) ($user->getData('username') ?? '');
            if ($username === '') {
                continue;
            }
            $count++;
            $hashes[] = $this->hashUsername($username);
            if ($includePlaintext) {
                $plaintext[] = $username;
            }
        }

        $evidence = [
            'count' => $count,
            'lookback_seconds' => self::LOOKBACK_SECONDS,
            'username_hashes' => $hashes,
            'detection_method' => 'admin_user.modified timestamp (proxy for role / permission change)',
        ];
        if ($includePlaintext) {
            $evidence['usernames'] = $plaintext;
        }

        return ['count' => $count, 'evidence' => $evidence];
    }

    /**
     * Admin password resets in the lookback window. `admin_passwords` is the
     * Magento_Security history table populated whenever an admin's password
     * is changed; we use its `last_updated` (unix timestamp column) plus a
     * join to `admin_user.username` so the evidence row can be hashed.
     *
     * Uses the framework Select builder — no raw SQL.
     *
     * @return array{count:int, evidence:array<string,mixed>}
     */
    private function recentPasswordResets(int $since, bool $includePlaintext): array
    {
        $connection = $this->resource->getConnection();
        $passwords = $this->resource->getTableName('admin_passwords');
        $users = $this->resource->getTableName('admin_user');

        $select = $connection->select()
            ->from(['p' => $passwords], ['user_id', 'last_updated'])
            ->joinLeft(['u' => $users], 'u.user_id = p.user_id', ['username'])
            ->where('p.last_updated >= ?', $since);

        $rows = $connection->fetchAll($select);

        $count = 0;
        $hashes = [];
        $plaintext = [];

        foreach ($rows as $row) {
            $username = (string) ($row['username'] ?? '');
            if ($username === '') {
                continue;
            }
            $count++;
            $hashes[] = $this->hashUsername($username);
            if ($includePlaintext) {
                $plaintext[] = $username;
            }
        }

        $evidence = [
            'count' => $count,
            'lookback_seconds' => self::LOOKBACK_SECONDS,
            'username_hashes' => $hashes,
        ];
        if ($includePlaintext) {
            $evidence['usernames'] = $plaintext;
        }

        return ['count' => $count, 'evidence' => $evidence];
    }

    /**
     * Distinct truncated IP prefixes observed in `admin_user_session.ip` over
     * the lookback window. Emitted INFO-only because without prior history
     * we cannot distinguish "new" IPs from regular ones — surfacing the
     * prefix list lets a human operator (or the Recon backend) compare
     * against the previous scan run's evidence.
     *
     * @return array{count:int, evidence:array<string,mixed>}
     */
    private function recentLoginIpPrefixes(string $sinceSql): array
    {
        $connection = $this->resource->getConnection();
        $sessions = $this->resource->getTableName('admin_user_session');

        $select = $connection->select()
            ->from(['s' => $sessions], ['ip'])
            ->where('s.updated_at >= ?', $sinceSql);

        $rows = $connection->fetchCol($select);

        $prefixes = [];
        foreach ($rows as $ip) {
            if (!is_string($ip) || $ip === '') {
                continue;
            }
            $prefixes[$this->truncateIp($ip)] = true;
        }

        $list = array_keys($prefixes);
        sort($list);

        return [
            'count' => count($list),
            'evidence' => [
                'count' => count($list),
                'lookback_seconds' => self::LOOKBACK_SECONDS,
                'ip_prefixes' => $list,
                'note' => 'Prefixes are /24 (IPv4) or /48 (IPv6); full IPs never transmitted.',
            ],
        ];
    }

    /**
     * Admin logins from `admin_user_session.updated_at` whose hour-of-day
     * falls outside the configured business-hours window. Suppressed when
     * either bound is unset (operator hasn't opted in to this signal).
     *
     * @return array{count:int, evidence:array<string,mixed>}
     */
    private function offHoursLogins(string $sinceSql, bool $includePlaintext): array
    {
        $start = $this->scopeConfig->getValue(self::CONFIG_BUSINESS_HOURS_START);
        $end = $this->scopeConfig->getValue(self::CONFIG_BUSINESS_HOURS_END);

        if ($start === null || $end === null || $start === '' || $end === '') {
            return ['count' => 0, 'evidence' => []];
        }

        $startHour = (int) $start;
        $endHour = (int) $end;
        if ($startHour < 0 || $startHour > 23 || $endHour < 0 || $endHour > 23 || $startHour === $endHour) {
            return ['count' => 0, 'evidence' => []];
        }

        $connection = $this->resource->getConnection();
        $sessions = $this->resource->getTableName('admin_user_session');
        $users = $this->resource->getTableName('admin_user');

        $select = $connection->select()
            ->from(['s' => $sessions], ['user_id', 'updated_at'])
            ->joinLeft(['u' => $users], 'u.user_id = s.user_id', ['username'])
            ->where('s.updated_at >= ?', $sinceSql);

        $rows = $connection->fetchAll($select);

        $count = 0;
        $hashes = [];
        $plaintext = [];

        foreach ($rows as $row) {
            $updatedAt = (string) ($row['updated_at'] ?? '');
            if ($updatedAt === '') {
                continue;
            }
            $ts = strtotime($updatedAt . ' UTC');
            if ($ts === false) {
                continue;
            }
            $hour = (int) gmdate('G', $ts);
            if (!$this->hourIsOffHours($hour, $startHour, $endHour)) {
                continue;
            }
            $username = (string) ($row['username'] ?? '');
            if ($username === '') {
                continue;
            }
            $count++;
            $hashes[] = $this->hashUsername($username);
            if ($includePlaintext) {
                $plaintext[] = $username;
            }
        }

        $evidence = [
            'count' => $count,
            'lookback_seconds' => self::LOOKBACK_SECONDS,
            'business_hours_start' => $startHour,
            'business_hours_end' => $endHour,
            'username_hashes' => $hashes,
        ];
        if ($includePlaintext) {
            $evidence['usernames'] = $plaintext;
        }

        return ['count' => $count, 'evidence' => $evidence];
    }

    /**
     * True when $hour is outside the [start, end) window. Handles overnight
     * windows (e.g. 22 -> 6) by inverting the test.
     */
    private function hourIsOffHours(int $hour, int $start, int $end): bool
    {
        if ($start < $end) {
            // Same-day window — e.g. 9 -> 17. Off-hours = outside [start, end).
            return $hour < $start || $hour >= $end;
        }

        // Overnight window — e.g. 22 -> 6. In-hours = [start, 24) ∪ [0, end).
        $inHours = $hour >= $start || $hour < $end;
        return !$inHours;
    }

    /**
     * SHA-256 of the username, truncated to {@see self::HASH_PREFIX_LEN}
     * hex characters. Deterministic across scan runs on the same merchant
     * so the Recon backend can correlate "user X did Y today AND yesterday".
     */
    private function hashUsername(string $username): string
    {
        return substr(hash('sha256', $username), 0, self::HASH_PREFIX_LEN);
    }

    /**
     * Truncate an IP address to its /24 (IPv4) or /48 (IPv6) prefix.
     * Returns the original input verbatim when it does not parse as either
     * — keeps the check tolerant of legacy / proxied session rows that
     * occasionally store hostnames in the `ip` column.
     */
    private function truncateIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed !== false) {
                $prefix = substr($packed, 0, 6) . str_repeat("\0", 10);
                $expanded = inet_ntop($prefix);
                if (is_string($expanded)) {
                    return $expanded . '/48';
                }
            }
        }
        return $ip;
    }
}
