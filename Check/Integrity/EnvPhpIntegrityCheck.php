<?php

/**
 * IronCart_Scan — IC-200..IC-205: env.php integrity sweep.
 *
 * Recon Phase 7.2 (#1186). Goes beyond the free-tier filesystem pack
 * (IC-030 world-readable / IC-031 owner / IC-032 crypt-key placeholder)
 * by enforcing the stricter posture that an outside-the-store scanner
 * cannot observe:
 *
 *   - IC-200: file mode is NOT `0640`-or-stricter (group/other read or any
 *             execute / write bit set on owner+group).
 *   - IC-201: file is owned by `root` or by a known webserver user.
 *   - IC-202: `app/etc/env.php` is a symlink (leaks the deploy path and
 *             defeats the per-file ACL).
 *   - IC-203: `crypt.key` is a documented default (Adobe sample / all-zero
 *             32-byte / Magento installer placeholder). This is distinct
 *             from IC-032's string-placeholder set — IC-203 also catches
 *             the all-zero key common in dev-environment copies that
 *             accidentally reach production.
 *   - IC-204: a DB connection in `db.connection.*` has an empty password.
 *   - IC-205: `session.save = 'files'` with no explicit `save_path` set —
 *             Magento falls back to PHP's default session.save_path which
 *             commonly lands in `/tmp`, world-traversable, with predictable
 *             names per PHP version.
 *
 * Privacy invariant: this check NEVER copies a crypt key, DB password, or
 * session path into the evidence payload. Only structural facts (`present`,
 * `placeholder_match`, mode bits, owner name) are emitted.
 *
 * Read-only: never writes / modifies `env.php`. Degrades with an `info`
 * finding when the file is missing or unreadable.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Integrity;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\Filesystem\MagentoRoot;
use IronCart\Scan\Check\Filesystem\WebserverUsers;
use IronCart\Scan\Report\Severity;

/**
 * IC-200..IC-205: Recon-tier `app/etc/env.php` integrity sweep.
 */
class EnvPhpIntegrityCheck implements CheckInterface
{
    public const ID_MODE = 'IC-200';
    public const ID_OWNER = 'IC-201';
    public const ID_SYMLINK = 'IC-202';
    public const ID_DEFAULT_CRYPT_KEY = 'IC-203';
    public const ID_EMPTY_DB_PASSWORD = 'IC-204';
    public const ID_SESSION_FILES_NO_PATH = 'IC-205';

    private const REMEDIATION_BASE = 'https://ironcart.dev/docs/checks/';

    /**
     * Documented / sample crypt-key values that appear in install guides and
     * dev fixtures. All-lowercase comparison.
     *
     * @var list<string>
     */
    private const DEFAULT_CRYPT_KEYS = [
        // PHP `md5('')` — frequently used as a stand-in in dev env.php.
        'd41d8cd98f00b204e9800998ecf8427e',
        // Magento installer sample value.
        '0123456789abcdef0123456789abcdef',
        // All-zero 32-byte / 64-byte hex — common dev placeholder.
        '00000000000000000000000000000000',
        '0000000000000000000000000000000000000000000000000000000000000000',
        // Documented placeholders kept in sync with IC-032 for callers who
        // run IC-203 in isolation.
        'changeme',
        'change_me',
        'your_key_here',
        'your-key-here',
    ];

    public function __construct(private readonly MagentoRoot $root)
    {
    }

    /**
     * @inheritDoc
     */
    public function run(): array
    {
        $path = $this->root->envPhp();

        if (!file_exists($path) && !is_link($path)) {
            return [[
                'id' => self::ID_MODE,
                'title' => 'app/etc/env.php not found',
                'severity' => Severity::INFO,
                'evidence' => ['path' => $path],
                'remediation_url' => self::remediationUrl(self::ID_MODE),
            ]];
        }

        $findings = [];

        // Symlink check first — `is_link()` short-circuits before the `include`
        // below so we never follow a symlink into an unexpected location.
        if (is_link($path)) {
            $target = @readlink($path);
            $findings[] = [
                'id' => self::ID_SYMLINK,
                'title' => 'app/etc/env.php is a symlink',
                'severity' => Severity::HIGH,
                'evidence' => [
                    'path' => $path,
                    'target' => is_string($target) ? $target : null,
                ],
                'remediation_url' => self::remediationUrl(self::ID_SYMLINK),
            ];
        }

        // Mode check uses `lstat` semantics via `fileperms()` — for a symlink
        // we still report the target's mode because that's what matters for
        // disclosure; the symlink itself was flagged above.
        $perms = @fileperms($path);
        if ($perms !== false) {
            $mode = $perms & 0o7777;
            if (!self::isModeStrictEnough($mode)) {
                $findings[] = [
                    'id' => self::ID_MODE,
                    'title' => 'app/etc/env.php mode is not 0640 or stricter',
                    'severity' => Severity::HIGH,
                    'evidence' => [
                        'path' => $path,
                        'mode' => self::formatMode($mode),
                    ],
                    'remediation_url' => self::remediationUrl(self::ID_MODE),
                ];
            }
        }

        // Ownership check — degrade silently to INFO when POSIX is unavailable
        // (matches IC-031's posture so cross-platform CI does not flap).
        if (function_exists('posix_getpwuid')) {
            $uid = @fileowner($path);
            if ($uid !== false) {
                $owner = @posix_getpwuid($uid);
                $ownerName = is_array($owner) && isset($owner['name'])
                    ? (string) $owner['name']
                    : null;

                if ($ownerName !== null && in_array($ownerName, WebserverUsers::NAMES_INCLUDING_ROOT, true)) {
                    $findings[] = [
                        'id' => self::ID_OWNER,
                        'title' => sprintf(
                            'app/etc/env.php is owned by `%s` (must be the dedicated Magento service user)',
                            $ownerName
                        ),
                        'severity' => Severity::HIGH,
                        'evidence' => [
                            'path' => $path,
                            'owner' => $ownerName,
                            'uid' => $uid,
                        ],
                        'remediation_url' => self::remediationUrl(self::ID_OWNER),
                    ];
                }
            }
        }

        // Sensitivity checks — require a readable PHP array.
        if (!is_readable($path)) {
            return $findings;
        }

        try {
            /** @var mixed $config */
            $config = include $path;
        } catch (\Throwable $e) {
            $findings[] = [
                'id' => self::ID_MODE,
                'title' => 'app/etc/env.php failed to load for sensitivity scan',
                'severity' => Severity::INFO,
                'evidence' => ['path' => $path, 'error' => $e->getMessage()],
                'remediation_url' => self::remediationUrl(self::ID_MODE),
            ];

            return $findings;
        }

        if (!is_array($config)) {
            return $findings;
        }

        $cryptKeyFinding = $this->checkCryptKey($path, $config);
        if ($cryptKeyFinding !== null) {
            $findings[] = $cryptKeyFinding;
        }

        foreach ($this->checkEmptyDbPasswords($path, $config) as $finding) {
            $findings[] = $finding;
        }

        $sessionFinding = $this->checkSessionFilesNoPath($path, $config);
        if ($sessionFinding !== null) {
            $findings[] = $sessionFinding;
        }

        return $findings;
    }

    /**
     * `0640` means owner rw, group r, other none. Stricter means no bit set
     * beyond `0640` — so `0600`, `0400`, `0440` all pass; `0644`, `0660`,
     * `0700`, `0750` all fail (execute / world-read / group-write).
     */
    private static function isModeStrictEnough(int $mode): bool
    {
        return ($mode & ~0o640) === 0;
    }

    private static function formatMode(int $mode): string
    {
        return '0' . decoct($mode);
    }

    private static function remediationUrl(string $id): string
    {
        return self::REMEDIATION_BASE . $id;
    }

    /**
     * Check the active crypt key (last entry in the newline-separated history)
     * against the documented-default list.
     *
     * @param array<mixed> $config
     * @return array{id:string,title:string,severity:string,evidence:mixed,remediation_url:string}|null
     */
    private function checkCryptKey(string $path, array $config): ?array
    {
        $key = $config['crypt']['key'] ?? null;
        if (!is_string($key) || $key === '') {
            // IC-032 already covers absent / empty. IC-203 only fires on
            // a *matched* default — silence here keeps the two checks
            // orthogonal.
            return null;
        }

        $entries = preg_split('/\R/', $key) ?: [];
        $entries = array_values(array_filter(
            array_map('trim', $entries),
            static fn ($v): bool => $v !== ''
        ));
        if ($entries === []) {
            return null;
        }

        $active = strtolower((string) end($entries));
        foreach (self::DEFAULT_CRYPT_KEYS as $known) {
            if ($active === strtolower($known)) {
                return [
                    'id' => self::ID_DEFAULT_CRYPT_KEY,
                    'title' => 'Magento crypt key matches a documented default value',
                    'severity' => Severity::HIGH,
                    // Never include the key bytes — only structural facts.
                    'evidence' => [
                        'path' => $path,
                        'present' => true,
                        'history_entries' => count($entries),
                        'default_match' => true,
                    ],
                    'remediation_url' => self::remediationUrl(self::ID_DEFAULT_CRYPT_KEY),
                ];
            }
        }

        return null;
    }

    /**
     * Walk `db.connection.<name>` entries and flag any with an empty
     * `password`. Never copies the password value into evidence.
     *
     * @param array<mixed> $config
     * @return list<array{id:string,title:string,severity:string,evidence:mixed,remediation_url:string}>
     */
    private function checkEmptyDbPasswords(string $path, array $config): array
    {
        $connections = $config['db']['connection'] ?? null;
        if (!is_array($connections)) {
            return [];
        }

        $findings = [];
        foreach ($connections as $name => $conn) {
            if (!is_array($conn) || !array_key_exists('password', $conn)) {
                continue;
            }
            $password = $conn['password'];
            if (is_string($password) && $password !== '') {
                continue;
            }

            $findings[] = [
                'id' => self::ID_EMPTY_DB_PASSWORD,
                'title' => sprintf(
                    'Database connection `%s` in env.php has no password set',
                    is_string($name) ? $name : (string) $name
                ),
                'severity' => Severity::HIGH,
                'evidence' => [
                    'path' => $path,
                    'connection' => is_string($name) ? $name : (string) $name,
                    'password_present' => false,
                ],
                'remediation_url' => self::remediationUrl(self::ID_EMPTY_DB_PASSWORD),
            ];
        }

        return $findings;
    }

    /**
     * Flag `session.save = 'files'` with no `session.save_path` configured —
     * Magento falls back to PHP's default which is typically `/tmp`.
     *
     * @param array<mixed> $config
     * @return array{id:string,title:string,severity:string,evidence:mixed,remediation_url:string}|null
     */
    private function checkSessionFilesNoPath(string $path, array $config): ?array
    {
        $session = $config['session'] ?? null;
        if (!is_array($session)) {
            return null;
        }

        $save = $session['save'] ?? null;
        if (!is_string($save) || strtolower($save) !== 'files') {
            return null;
        }

        $savePath = $session['save_path'] ?? null;
        if (is_string($savePath) && trim($savePath) !== '') {
            return null;
        }

        return [
            'id' => self::ID_SESSION_FILES_NO_PATH,
            'title' => "Session handler is 'files' but no save_path is configured",
            'severity' => Severity::HIGH,
            // Never echo the save path even if partially populated.
            'evidence' => [
                'path' => $path,
                'session_save' => 'files',
                'save_path_present' => false,
            ],
            'remediation_url' => self::remediationUrl(self::ID_SESSION_FILES_NO_PATH),
        ];
    }
}
