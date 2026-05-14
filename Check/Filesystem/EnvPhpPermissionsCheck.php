<?php

/**
 * IronCart_Scan — IC-030: `app/etc/env.php` permissions.
 *
 * `app/etc/env.php` holds the encryption key, DB credentials, and Magento
 * configuration secrets. A world-readable mode bit on this file means any
 * local account on the host can read it. Adobe's hardening guide calls for
 * `640` or stricter on shared hosts.
 *
 * Skips with an `info` finding on hosts where `fileperms()` returns false
 * (e.g. unreadable parent directory).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Filesystem;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Report\Severity;

/**
 * IC-030: flag `app/etc/env.php` if its mode bits include world-read.
 */
class EnvPhpPermissionsCheck implements CheckInterface
{
    public const ID = 'IC-030';

    private const TITLE = 'app/etc/env.php is world-readable';
    private const REMEDIATION_URL =
        'https://experienceleague.adobe.com/docs/commerce-operations/installation-guide/prerequisites/file-system/overview.html';

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

        $perms = @fileperms($path);
        if ($perms === false) {
            return [[
                'id' => self::ID,
                'title' => 'app/etc/env.php permissions could not be read',
                'severity' => Severity::INFO,
                'evidence' => ['path' => $path],
                'remediation_url' => self::REMEDIATION_URL,
            ]];
        }

        // Lower 12 bits are the permission set (incl. setuid/setgid/sticky).
        $mode = $perms & 0o7777;
        $worldReadable = ($mode & 0o004) === 0o004;

        if (!$worldReadable) {
            return [];
        }

        return [[
            'id' => self::ID,
            'title' => self::TITLE,
            'severity' => Severity::HIGH,
            'evidence' => [
                'path' => $path,
                'mode' => $this->formatMode($mode),
            ],
            'remediation_url' => self::REMEDIATION_URL,
        ]];
    }

    /**
     * Format a permission integer as an octal string (`"0644"`).
     */
    private function formatMode(int $mode): string
    {
        return '0' . decoct($mode);
    }
}
