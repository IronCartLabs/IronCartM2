<?php

/**
 * IronCart_Scan — `ironcart_scan/upload/*` default-config assertions.
 *
 * Verifies the hard "opt-in default OFF" invariant from
 * IronCartLabs/IronCartM2#57: a fresh `composer require` of this module
 * must NEVER ship with upload enabled. The unit test parses
 * `etc/config.xml` directly and asserts the default value of the
 * `enabled` flag is `0`. Anything else would mean operators could be
 * unwittingly uploading scan data after a routine module update.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Upload;

use IronCart\Scan\Check\Upload\UploadConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\Upload\UploadConfig
 */
class UploadConfigDefaultsTest extends TestCase
{
    public function testEnabledDefaultIsZero(): void
    {
        $xml = $this->loadConfigXml();
        $node = $xml->xpath('/config/default/ironcart_scan/upload/enabled');
        self::assertNotEmpty($node, 'etc/config.xml must declare a default for ironcart_scan/upload/enabled');
        self::assertSame('0', (string) $node[0], 'Hard invariant: upload must default OFF');
    }

    public function testEndpointDefaultPointsAtProductionIroncartDev(): void
    {
        $xml = $this->loadConfigXml();
        $node = $xml->xpath('/config/default/ironcart_scan/upload/endpoint');
        self::assertNotEmpty($node);
        self::assertSame(
            'https://ironcart.dev/api/scan/ingest',
            (string) $node[0]
        );
    }

    public function testAllowedHostDefaultIsIroncartDev(): void
    {
        $xml = $this->loadConfigXml();
        $node = $xml->xpath('/config/default/ironcart_scan/upload/allowed_host');
        self::assertNotEmpty($node);
        self::assertSame('ironcart.dev', (string) $node[0]);
        // Also assert the constant matches so the runtime default and
        // the XML default stay aligned.
        self::assertSame(UploadConfig::DEFAULT_ALLOWED_HOST, (string) $node[0]);
    }

    private function loadConfigXml(): \SimpleXMLElement
    {
        $path = __DIR__ . '/../../../../etc/config.xml';
        self::assertFileExists($path, 'etc/config.xml must exist for the upload defaults');
        $xml = simplexml_load_file($path);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);
        return $xml;
    }
}
