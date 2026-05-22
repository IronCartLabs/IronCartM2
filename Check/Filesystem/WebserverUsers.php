<?php

/**
 * IronCart_Scan — shared webserver-user allow-list.
 *
 * Single source of truth for the login names that conventionally denote the
 * webserver process user. Used by:
 *
 *   - {@see EnvPhpOwnershipCheck} (IC-031, free tier) — flags env.php owned
 *     by any of {@see self::NAMES}.
 *   - {@see \IronCart\Scan\Check\Integrity\EnvPhpIntegrityCheck} (IC-201,
 *     Recon tier) — flags env.php owned by any of
 *     {@see self::NAMES_INCLUDING_ROOT}, i.e. the free-tier list plus `root`.
 *
 * Constants-only; no behaviour. Bumping this list is a posture change shared
 * by both checks — please update the pinning test
 * `Test/Unit/Check/Filesystem/WebserverUsersTest.php` when adding entries.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Filesystem;

/**
 * Shared webserver-user login names referenced by IC-031 and IC-201.
 */
final class WebserverUsers
{
    /**
     * Login names that conventionally denote the webserver process user.
     *
     * @var list<string>
     */
    public const NAMES = [
        'www-data',
        'nginx',
        'apache',
        'apache2',
        'httpd',
        'http',
        'nobody',
    ];

    /**
     * {@see self::NAMES} plus `root`. Recon-tier ownership check (IC-201)
     * treats root-owned env.php as a finding even though IC-031 ignores it.
     *
     * @var list<string>
     */
    public const NAMES_INCLUDING_ROOT = [
        'root',
        'www-data',
        'nginx',
        'apache',
        'apache2',
        'httpd',
        'http',
        'nobody',
    ];

    private function __construct()
    {
    }
}
