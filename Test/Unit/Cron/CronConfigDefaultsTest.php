<?php

/**
 * IronCart_Scan — `ironcart_scan/cron/*` default-config assertions.
 *
 * Verifies the hard "opt-in default OFF" invariant from
 * IronCartLabs/IronCartM2#64: a fresh `composer require` of this module
 * must NEVER ship with the continuous-monitoring cron enabled. The unit
 * test parses `etc/config.xml` directly and asserts the default value
 * of the `cron/enabled` flag is `0`. Anything else would mean operators
 * could start uploading scan data on a cron tick after a routine
 * `composer update` without any explicit consent.
 *
 * Mirrors the discipline of {@see \IronCart\Scan\Test\Unit\Check\Upload
 * \UploadConfigDefaultsTest}.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Cron;

use IronCart\Scan\Cron\UploadScan;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Cron\UploadScan
 */
class CronConfigDefaultsTest extends TestCase
{
    public function testCronEnabledDefaultIsZero(): void
    {
        $xml = $this->loadConfigXml();
        $node = $xml->xpath('/config/default/ironcart_scan/cron/enabled');
        self::assertNotEmpty(
            $node,
            'etc/config.xml must declare a default for ironcart_scan/cron/enabled'
        );
        self::assertSame(
            '0',
            (string) $node[0],
            'Hard invariant per #64: continuous-monitoring cron must default OFF'
        );
    }

    public function testCronScheduleDefaultIsDaily0300(): void
    {
        $xml = $this->loadConfigXml();
        $node = $xml->xpath('/config/default/ironcart_scan/cron/schedule');
        self::assertNotEmpty(
            $node,
            'etc/config.xml must declare a default schedule for ironcart_scan/cron/schedule'
        );
        self::assertSame(
            '0 3 * * *',
            (string) $node[0],
            'Default schedule must be `0 3 * * *` per #64'
        );
    }

    public function testCrontabDeclaresIroncartScanUploadCronJob(): void
    {
        $path = __DIR__ . '/../../../etc/crontab.xml';
        self::assertFileExists($path, 'etc/crontab.xml must exist for the v4 cron group');
        $xml = simplexml_load_file($path);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);

        $jobs = $xml->xpath('/config/group[@id="ironcart_scan"]/job[@name="ironcart_scan_upload_cron"]');
        self::assertNotEmpty(
            $jobs,
            'crontab.xml must declare the `ironcart_scan_upload_cron` job under group `ironcart_scan`'
        );
        $job = $jobs[0];
        self::assertSame(
            UploadScan::class,
            (string) $job['instance'],
            'crontab.xml job must point at IronCart\\Scan\\Cron\\UploadScan'
        );
        self::assertSame(
            'execute',
            (string) $job['method'],
            'crontab.xml job must invoke ::execute()'
        );

        // `<config_path>` lets operators move the schedule from admin
        // without editing XML — checked here so a future refactor that
        // drops it can't ship silently.
        $configPath = $xml->xpath(
            '/config/group[@id="ironcart_scan"]/job[@name="ironcart_scan_upload_cron"]/config_path'
        );
        self::assertNotEmpty($configPath, 'cron job must wire <config_path> for the admin schedule field');
        self::assertSame('ironcart_scan/cron/schedule', (string) $configPath[0]);
    }

    private function loadConfigXml(): \SimpleXMLElement
    {
        $path = __DIR__ . '/../../../etc/config.xml';
        self::assertFileExists($path, 'etc/config.xml must exist for the cron defaults');
        $xml = simplexml_load_file($path);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);
        return $xml;
    }
}
