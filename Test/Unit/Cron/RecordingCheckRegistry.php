<?php

/**
 * IronCart_Scan — recording stand-in for {@see CheckRegistry}.
 *
 * Counts `runAll()` invocations without loading any production check
 * classes, returns one benign finding so the upload pipeline has
 * something to send. Lives in its own file to satisfy the Magento2 /
 * PSR-12 "Each class must be in a file by itself" sniff.
 *
 * Test-only fixture for {@see \IronCart\Scan\Test\Unit\Cron\UploadScanTest}.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Cron;

use IronCart\Scan\Check\CheckRegistry;
use IronCart\Scan\Report\Severity;

/**
 * Recording stand-in for {@see CheckRegistry} that counts `runAll()`
 * invocations without loading any production check classes. Returns a
 * single benign finding so the upload pipeline has something to send.
 */
final class RecordingCheckRegistry extends CheckRegistry
{
    public int $runs = 0;

    public function __construct()
    {
        parent::__construct([]);
    }

    /**
     * @return list<array{
     *     id:string,
     *     title:string,
     *     severity:string,
     *     evidence:mixed,
     *     remediation_url:string
     * }>
     */
    public function runAll(): array
    {
        $this->runs++;
        return [[
            'id' => 'IC-020',
            'title' => 'mage mode',
            'severity' => Severity::CRITICAL,
            'evidence' => ['mage_mode' => 'developer'],
            'remediation_url' => 'https://ironcart.dev/docs/checks/IC-020',
        ]];
    }
}
