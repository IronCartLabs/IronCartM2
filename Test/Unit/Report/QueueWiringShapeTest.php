<?php

/**
 * IronCart_Scan — queue wiring structural smoke test.
 *
 * Pins the four MessageQueue XML files added for the async pipeline so
 * a rename / typo / wrong-namespace mistake shows up as a failing test
 * in the Magento-free unit job rather than at integration-job runtime
 * (which currently won't run on free-tier PRs without secrets — see
 * .github/workflows/ci.yml comments on the `integration` job).
 *
 * The structural pin tested here covers:
 *   - topic name `ironcart.scan.run` (must match in communication,
 *     topology binding, publisher, and the const on ScanRunPublisher)
 *   - consumer name `ironcartScanRunConsumer`
 *   - consumer handler `IronCart\Scan\Model\ScanRunConsumer::process`
 *   - exchange + queue + connection (DB queue invariant for v1)
 *
 * Runs under Test/Unit/Report because that is the only testsuite the
 * unit-CI cell loads — see ReportBuilderTest / DbSchemaShapeTest for
 * the same convention. The file is intentionally not under
 * Test/Unit/Model so the existing CI phpunit.xml override does not
 * have to be touched.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Report;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * @coversNothing
 */
class QueueWiringShapeTest extends TestCase
{
    private const ETC = __DIR__ . '/../../../etc';

    private const TOPIC = 'ironcart.scan.run';
    private const QUEUE = 'ironcart.scan.run.queue';
    private const EXCHANGE = 'ironcart-scan-exchange';
    private const CONSUMER = 'ironcartScanRunConsumer';
    private const HANDLER = 'IronCart\\Scan\\Model\\ScanRunConsumer::process';

    public function testCommunicationXmlDeclaresTopicAndHandler(): void
    {
        $xml = $this->loadXml('communication.xml');

        $topic = $this->firstByName($xml->topic, self::TOPIC);
        self::assertNotNull($topic, 'communication.xml must declare topic ' . self::TOPIC);
        self::assertSame('string', (string)$topic['request'], 'topic payload must be `string`');

        $handler = $topic->handler[0];
        self::assertNotNull($handler, 'topic must declare a handler');
        self::assertSame(
            'IronCart\\Scan\\Model\\ScanRunConsumer',
            (string)$handler['type'],
            'handler must point at ScanRunConsumer'
        );
        self::assertSame('process', (string)$handler['method']);
    }

    public function testQueueTopologyBindsTopicToDbQueue(): void
    {
        $xml = $this->loadXml('queue_topology.xml');

        $exchange = $this->firstByName($xml->exchange, self::EXCHANGE);
        self::assertNotNull($exchange, 'topology must declare exchange ' . self::EXCHANGE);
        self::assertSame('topic', (string)$exchange['type']);
        self::assertSame('db', (string)$exchange['connection'], 'v1 invariant — DB queue only');

        $binding = $exchange->binding[0];
        self::assertNotNull($binding, 'exchange must declare a binding');
        self::assertSame(self::TOPIC, (string)$binding['topic']);
        self::assertSame('queue', (string)$binding['destinationType']);
        self::assertSame(self::QUEUE, (string)$binding['destination']);
    }

    public function testQueuePublisherRoutesTopicToDbConnection(): void
    {
        $xml = $this->loadXml('queue_publisher.xml');

        $publisher = null;
        foreach ($xml->publisher as $candidate) {
            if ((string)$candidate['topic'] === self::TOPIC) {
                $publisher = $candidate;
                break;
            }
        }
        self::assertNotNull($publisher, 'publisher must route ' . self::TOPIC);

        $connection = $publisher->connection[0];
        self::assertNotNull($connection);
        self::assertSame('db', (string)$connection['name']);
        self::assertSame(self::EXCHANGE, (string)$connection['exchange']);
    }

    public function testQueueConsumerDeclaresHandler(): void
    {
        $xml = $this->loadXml('queue_consumer.xml');

        $consumer = $this->firstByName($xml->consumer, self::CONSUMER);
        self::assertNotNull(
            $consumer,
            'queue_consumer.xml must declare consumer ' . self::CONSUMER
        );
        self::assertSame(self::QUEUE, (string)$consumer['queue']);
        self::assertSame('db', (string)$consumer['connection']);
        self::assertSame(self::HANDLER, (string)$consumer['handler']);
    }

    private function loadXml(string $filename): SimpleXMLElement
    {
        $path = self::ETC . '/' . $filename;
        self::assertFileExists($path, "etc/{$filename} must exist");

        $xml = simplexml_load_file($path);
        self::assertInstanceOf(SimpleXMLElement::class, $xml, "etc/{$filename} must parse");

        return $xml;
    }

    /**
     * SimpleXML cannot be iterated when null, and `->topic[0]` requires
     * a name lookup. Helper hides the boilerplate.
     */
    private function firstByName(SimpleXMLElement $nodes, string $name): ?SimpleXMLElement
    {
        foreach ($nodes as $node) {
            if ((string)$node['name'] === $name) {
                return $node;
            }
        }
        return null;
    }
}
