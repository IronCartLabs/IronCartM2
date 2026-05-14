<?php

/**
 * IronCart_Scan — IC-040 unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Operational;

use IronCart\Scan\Check\Operational\IndexerStateCheck;
use IronCart\Scan\Report\Severity;
use Magento\Framework\Indexer\ConfigInterface as IndexerConfig;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerInterfaceFactory;
use Magento\Framework\Indexer\StateInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class IndexerStateCheckTest extends TestCase
{
    public function testValidIndexersAreIgnored(): void
    {
        $config = $this->createMock(IndexerConfig::class);
        $config->method('getIndexers')->willReturn([
            'catalog_product_price' => [],
        ]);

        $indexer = $this->makeIndexer(
            id: 'catalog_product_price',
            status: StateInterface::STATUS_VALID,
            updatedAt: gmdate('Y-m-d H:i:s', time() - 3600 * 48)
        );
        $factory = $this->makeFactory([$indexer]);

        $check = new IndexerStateCheck($config, $factory);
        self::assertSame([], $check->run());
    }

    public function testRecentlyInvalidatedIndexersAreIgnored(): void
    {
        $config = $this->createMock(IndexerConfig::class);
        $config->method('getIndexers')->willReturn([
            'catalog_product_price' => [],
        ]);

        $indexer = $this->makeIndexer(
            id: 'catalog_product_price',
            status: StateInterface::STATUS_INVALID,
            updatedAt: gmdate('Y-m-d H:i:s', time() - 60)
        );
        $factory = $this->makeFactory([$indexer]);

        $check = new IndexerStateCheck($config, $factory);
        self::assertSame([], $check->run());
    }

    public function testStaleInvalidIndexersFlagMedium(): void
    {
        $config = $this->createMock(IndexerConfig::class);
        $config->method('getIndexers')->willReturn([
            'catalog_product_price' => [],
            'cataloginventory_stock' => [],
        ]);

        $stale = $this->makeIndexer(
            id: 'catalog_product_price',
            status: StateInterface::STATUS_INVALID,
            updatedAt: gmdate('Y-m-d H:i:s', time() - (3600 * 30))
        );
        $fresh = $this->makeIndexer(
            id: 'cataloginventory_stock',
            status: StateInterface::STATUS_VALID,
            updatedAt: gmdate('Y-m-d H:i:s', time() - 60)
        );
        $factory = $this->makeFactory([$stale, $fresh]);

        $check = new IndexerStateCheck($config, $factory);
        $findings = $check->run();

        self::assertCount(1, $findings);
        self::assertSame('IC-040', $findings[0]['id']);
        self::assertSame(Severity::MEDIUM, $findings[0]['severity']);
        self::assertSame(24, $findings[0]['evidence']['threshold_hours']);
        self::assertCount(1, $findings[0]['evidence']['indexers']);
        self::assertSame('catalog_product_price', $findings[0]['evidence']['indexers'][0]['indexer_id']);
        self::assertGreaterThanOrEqual(30, $findings[0]['evidence']['indexers'][0]['age_hours']);
    }

    /**
     * @param list<IndexerInterface&MockObject> $indexers
     */
    private function makeFactory(array $indexers): IndexerInterfaceFactory&MockObject
    {
        $factory = $this->createMock(IndexerInterfaceFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls(...$indexers);

        return $factory;
    }

    private function makeIndexer(string $id, string $status, string $updatedAt): IndexerInterface&MockObject
    {
        $state = $this->createMock(StateInterface::class);
        $state->method('getStatus')->willReturn($status);
        $state->method('getUpdated')->willReturn($updatedAt);

        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('load')->willReturnSelf();
        $indexer->method('getState')->willReturn($state);
        $indexer->method('getTitle')->willReturn(ucwords(str_replace('_', ' ', $id)));

        return $indexer;
    }
}
