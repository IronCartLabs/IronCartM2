<?php

/**
 * IronCart_Scan — IC-033: `pub/media` and `var/` permissions.
 *
 * Magento writes uploads to `pub/media` and runtime artefacts to `var/`. Both
 * must be writable by the PHP-FPM process but neither should be world-writable
 * — a world-writable upload directory is a stepping stone for arbitrary file
 * upload chains.
 *
 * Skips with `info` if the directory is missing (e.g. the scanner is being
 * run against a partial deployment).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Filesystem;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Report\Severity;

/**
 * IC-033: flag `pub/media` and `var/` if they are world-writable.
 */
class WritableDirectoriesCheck implements CheckInterface
{
    public const ID = 'IC-033';

    private const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-033';

    private const TARGETS = ['pub/media', 'var'];

    public function __construct(private readonly MagentoRoot $root)
    {
    }

    /**
     * @inheritDoc
     */
    public function run(): array
    {
        $findings = [];

        foreach (self::TARGETS as $relative) {
            $path = $this->root->join($relative);

            if (!is_dir($path)) {
                $findings[] = [
                    'id' => self::ID,
                    'title' => sprintf('%s not found', $relative),
                    'severity' => Severity::INFO,
                    'evidence' => ['path' => $path],
                    'remediation_url' => self::REMEDIATION_URL,
                ];
                continue;
            }

            $perms = @fileperms($path);
            if ($perms === false) {
                $findings[] = [
                    'id' => self::ID,
                    'title' => sprintf('%s permissions could not be read', $relative),
                    'severity' => Severity::INFO,
                    'evidence' => ['path' => $path],
                    'remediation_url' => self::REMEDIATION_URL,
                ];
                continue;
            }

            $mode = $perms & 0o7777;
            $worldWritable = ($mode & 0o002) === 0o002;

            if (!$worldWritable) {
                continue;
            }

            $findings[] = [
                'id' => self::ID,
                'title' => sprintf('%s is world-writable', $relative),
                'severity' => Severity::MEDIUM,
                'evidence' => [
                    'path' => $path,
                    'mode' => '0' . decoct($mode),
                ],
                'remediation_url' => self::REMEDIATION_URL,
            ];
        }

        return $findings;
    }
}
