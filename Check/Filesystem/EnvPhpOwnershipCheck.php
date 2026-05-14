<?php

/**
 * IronCart_Scan — IC-031: `app/etc/env.php` ownership.
 *
 * If `app/etc/env.php` is owned by the webserver user (commonly `www-data`,
 * `nginx`, `apache`, or `http`) then a webserver-side RCE can rewrite the
 * file. Adobe's hardening guidance places ownership on a separate deploy
 * user; the webserver user should retain group-read only.
 *
 * Degrades with an `info` finding on hosts where `posix_*` is unavailable
 * (e.g. Windows or PHP built without `--enable-posix`).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Filesystem;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Report\Severity;

/**
 * IC-031: flag `app/etc/env.php` if owned by a known webserver account.
 */
class EnvPhpOwnershipCheck implements CheckInterface
{
    public const ID = 'IC-031';

    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-031';

    /**
     * Login names that conventionally denote the webserver process user.
     *
     * @var list<string>
     */
    private const WEBSERVER_USERS = [
        'www-data',
        'nginx',
        'apache',
        'apache2',
        'httpd',
        'http',
        'nobody',
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

        if (!is_file($path)) {
            return [[
                'id' => self::ID,
                'title' => 'app/etc/env.php not found',
                'severity' => Severity::INFO,
                'evidence' => ['path' => $path],
                'remediation_url' => self::REMEDIATION_URL,
            ]];
        }

        if (!function_exists('posix_getpwuid')) {
            return [[
                'id' => self::ID,
                'title' => 'app/etc/env.php ownership not checked (posix extension unavailable)',
                'severity' => Severity::INFO,
                'evidence' => ['path' => $path],
                'remediation_url' => self::REMEDIATION_URL,
            ]];
        }

        $uid = @fileowner($path);
        if ($uid === false) {
            return [[
                'id' => self::ID,
                'title' => 'app/etc/env.php ownership could not be read',
                'severity' => Severity::INFO,
                'evidence' => ['path' => $path],
                'remediation_url' => self::REMEDIATION_URL,
            ]];
        }

        $owner = @posix_getpwuid($uid);
        $ownerName = is_array($owner) && isset($owner['name']) ? (string) $owner['name'] : null;

        if ($ownerName === null) {
            return [[
                'id' => self::ID,
                'title' => 'app/etc/env.php owner uid could not be resolved to a name',
                'severity' => Severity::INFO,
                'evidence' => ['path' => $path, 'uid' => $uid],
                'remediation_url' => self::REMEDIATION_URL,
            ]];
        }

        if (!in_array($ownerName, self::WEBSERVER_USERS, true)) {
            return [];
        }

        return [[
            'id' => self::ID,
            'title' => 'app/etc/env.php is owned by the webserver user',
            'severity' => Severity::MEDIUM,
            'evidence' => [
                'path' => $path,
                'owner' => $ownerName,
                'uid' => $uid,
            ],
            'remediation_url' => self::REMEDIATION_URL,
        ]];
    }
}
