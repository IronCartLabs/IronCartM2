<?php

/**
 * IronCart_Scan — ScanRunMessage DTO tests.
 *
 * Magento-free coverage of the wire payload that travels over the
 * `ironcart.scan.run` topic. Lives under Test/Unit/Report so the unit
 * CI cell loads it (see ci.yml — only Test/Unit/Report is enumerated
 * in the override phpunit.xml because that subtree has no
 * Magento\Framework imports).
 *
 * The DTO itself lives at IronCart\Scan\Model\Message\ScanRunMessage;
 * the autoloader resolves it via the project-wide PSR-4 root mapped in
 * composer.json (`"IronCart\\Scan\\": ""`).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Report;

use InvalidArgumentException;
use IronCart\Scan\Model\Message\ScanRunMessage;
use PHPUnit\Framework\TestCase;

class ScanRunMessageTest extends TestCase
{
    public function testRoundTripsArrayPayload(): void
    {
        $message = new ScanRunMessage(42, 'admin:7');
        $payload = $message->toArray();

        self::assertSame(['scan_run_id' => 42, 'triggered_by' => 'admin:7'], $payload);

        $decoded = ScanRunMessage::fromArray($payload);
        self::assertSame(42, $decoded->getScanRunId());
        self::assertSame('admin:7', $decoded->getTriggeredBy());
    }

    public function testConstructorRejectsNonPositiveRunId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScanRunMessage(0, 'cli');
    }

    public function testConstructorRejectsEmptyTriggeredBy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScanRunMessage(1, '');
    }

    public function testFromArrayRejectsMissingRunId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScanRunMessage::fromArray(['triggered_by' => 'cli']);
    }

    public function testFromArrayRejectsNonIntRunId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScanRunMessage::fromArray(['scan_run_id' => '42', 'triggered_by' => 'cli']);
    }

    public function testFromArrayRejectsMissingTriggeredBy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScanRunMessage::fromArray(['scan_run_id' => 1]);
    }
}
