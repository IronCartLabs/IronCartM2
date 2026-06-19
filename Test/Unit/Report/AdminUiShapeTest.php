<?php

/**
 * IronCart_Scan — admin UI Component shape smoke test.
 *
 * Pins the declarative XML for the v1 admin grids so an accidental
 * rename / wrong-data-provider / wrong-ACL surfaces in the
 * Magento-free unit cell rather than waiting for the integration
 * matrix. Lives under Test/Unit/Report for the same reason
 * DbSchemaShapeTest / QueueWiringShapeTest do — it's the only
 * testsuite the unit-CI cell loads (see .github/workflows/ci.yml).
 *
 * What this test does NOT do:
 *   - Boot Magento or instantiate the UI Component classes
 *     (the unit cell strips magento/framework before composer install)
 *   - Verify the columns render in a browser (visual smoke is the
 *     integration job's responsibility)
 *
 * What this test DOES do:
 *   - Parses each XML file and asserts the load-bearing handles,
 *     data-provider class binding, ACL resource, and column set
 *   - Pins layout handles to controller route IDs
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Report;

use IronCart\Scan\Model\ResourceModel\ScanFinding\Collection as ScanFindingCollection;
use IronCart\Scan\Model\ResourceModel\ScanRun\Collection as ScanRunCollection;
use IronCart\Scan\Ui\Component\Listing\Column\Options\SeverityOptions;
use IronCart\Scan\Ui\DataProvider\ScanFindingDataProvider;
use IronCart\Scan\Ui\DataProvider\ScanRunDataProvider;
use Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory as UiCollectionFactory;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * @coversNothing
 */
class AdminUiShapeTest extends TestCase
{
    private const MODULE_ROOT = __DIR__ . '/../../..';
    private const RUN_LISTING = self::MODULE_ROOT . '/view/adminhtml/ui_component/ironcartscan_run_listing.xml';
    private const FINDING_LISTING = self::MODULE_ROOT . '/view/adminhtml/ui_component/ironcartscan_finding_listing.xml';
    private const LAYOUT_INDEX = self::MODULE_ROOT . '/view/adminhtml/layout/ironcartscan_scans_index.xml';
    private const LAYOUT_VIEW = self::MODULE_ROOT . '/view/adminhtml/layout/ironcartscan_scans_view.xml';
    private const GLOBAL_DI = self::MODULE_ROOT . '/etc/di.xml';
    private const ADMINHTML_DI = self::MODULE_ROOT . '/etc/adminhtml/di.xml';

    public function testRunListingXmlIsParseable(): void
    {
        self::assertFileExists(self::RUN_LISTING);
        $xml = simplexml_load_file(self::RUN_LISTING);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);
        self::assertSame('listing', $xml->getName());
    }

    public function testRunListingBindsToScanRunDataProvider(): void
    {
        $xml = simplexml_load_file(self::RUN_LISTING);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $provider = $xml->dataSource->dataProvider ?? null;
        self::assertNotNull($provider, 'run-listing must declare a dataProvider');
        self::assertSame(
            ScanRunDataProvider::class,
            (string)$provider['class']
        );
    }

    public function testRunListingGatesOnViewAcl(): void
    {
        $xml = simplexml_load_file(self::RUN_LISTING);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $acl = (string)($xml->dataSource->aclResource ?? '');
        self::assertSame(
            'IronCart_Scan::view',
            $acl,
            'run-listing data source must gate on IronCart_Scan::view'
        );
    }

    public function testRunListingDeclaresRequiredColumns(): void
    {
        $xml = simplexml_load_file(self::RUN_LISTING);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $columnsBlock = null;
        foreach ($xml->columns as $candidate) {
            if ((string)$candidate['name'] === 'ironcartscan_run_listing_columns') {
                $columnsBlock = $candidate;
                break;
            }
        }
        self::assertNotNull($columnsBlock, 'run-listing must declare a columns block');

        $names = $this->columnNames($columnsBlock);
        foreach (['entity_id', 'status', 'triggered_by', 'started_at', 'finished_at', 'severity_totals', 'finding_count'] as $col) {
            self::assertContains($col, $names, "run-listing must include column `{$col}`");
        }

        // Per-row View action column — kept separate from <column> nodes.
        $actionNames = [];
        foreach ($columnsBlock->actionsColumn as $action) {
            $actionNames[] = (string)$action['name'];
        }
        self::assertContains('actions', $actionNames, 'run-listing must include per-row actions column');
    }

    /**
     * @return array<string,array{string,string,string}>
     */
    public static function runListingFilterableColumnsProvider(): array
    {
        // Issue #118 AC: run-listing exposes column filters on at
        // least: scan timestamp (date range), status (select),
        // summary counts (numeric range). Each tuple is
        // [columnName, expectedFilterType, expectedDataType].
        return [
            'status select filter'         => ['status',        'select',    'select'],
            'started_at date range filter' => ['started_at',    'dateRange', 'date'],
            'finished_at date range filter' => ['finished_at',  'dateRange', 'date'],
            'finding_count numeric range'  => ['finding_count', 'textRange', 'text'],
        ];
    }

    /**
     * @dataProvider runListingFilterableColumnsProvider
     */
    public function testRunListingDeclaresFilterOnColumn(string $columnName, string $filter, string $dataType): void
    {
        $xml = simplexml_load_file(self::RUN_LISTING);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $column = null;
        foreach ($xml->columns->column as $col) {
            if ((string)$col['name'] === $columnName) {
                $column = $col;
                break;
            }
        }
        self::assertNotNull($column, "run-listing must declare column `{$columnName}`");

        self::assertSame(
            $filter,
            trim((string)$column->settings->filter),
            "run-listing column `{$columnName}` must declare <filter>{$filter}</filter> (issue #118 AC)"
        );
        self::assertSame(
            $dataType,
            trim((string)$column->settings->dataType),
            "run-listing column `{$columnName}` must declare <dataType>{$dataType}</dataType>"
        );
    }

    /**
     * @return array<string,array{string,string,string}>
     */
    public static function findingListingFilterableColumnsProvider(): array
    {
        // Issue #118 AC: finding-listing exposes column filters on at
        // least: severity (select), check id (text), title (text),
        // plus the existing dateRange on created_at. severity is
        // pinned separately by
        // testFindingListingSeverityColumnDeclaresStandardSelectFilter
        // because it has additional <options> wiring to assert.
        return [
            'check_id text filter'      => ['check_id',   'text',      'text'],
            'title text filter'         => ['title',      'text',      'text'],
            'created_at date range'     => ['created_at', 'dateRange', 'date'],
        ];
    }

    /**
     * @dataProvider findingListingFilterableColumnsProvider
     */
    public function testFindingListingDeclaresFilterOnColumn(string $columnName, string $filter, string $dataType): void
    {
        $xml = simplexml_load_file(self::FINDING_LISTING);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $column = null;
        foreach ($xml->columns->column as $col) {
            if ((string)$col['name'] === $columnName) {
                $column = $col;
                break;
            }
        }
        self::assertNotNull($column, "finding-listing must declare column `{$columnName}`");

        self::assertSame(
            $filter,
            trim((string)$column->settings->filter),
            "finding-listing column `{$columnName}` must declare <filter>{$filter}</filter> (issue #118 AC)"
        );
        self::assertSame(
            $dataType,
            trim((string)$column->settings->dataType),
            "finding-listing column `{$columnName}` must declare <dataType>{$dataType}</dataType>"
        );
    }

    public function testFindingListingBindsToScanFindingDataProvider(): void
    {
        $xml = simplexml_load_file(self::FINDING_LISTING);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $provider = $xml->dataSource->dataProvider ?? null;
        self::assertNotNull($provider, 'finding-listing must declare a dataProvider');
        self::assertSame(
            ScanFindingDataProvider::class,
            (string)$provider['class']
        );
    }

    public function testFindingListingSeverityColumnDeclaresStandardSelectFilter(): void
    {
        // AC (issue #106): the bespoke "Show all severities" header
        // button is gone — admins narrow the grid via the standard
        // Magento column-filter UI on the severity column. The
        // column must therefore still declare `<filter>select</filter>`
        // backed by `SeverityOptions`. Anyone stripping the filter
        // type or switching to a different options source would break
        // the only severity-narrowing affordance the v1 grid has.
        $xml = simplexml_load_file(self::FINDING_LISTING);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $severity = null;
        foreach ($xml->columns->column as $col) {
            if ((string)$col['name'] === 'severity') {
                $severity = $col;
                break;
            }
        }
        self::assertNotNull($severity, 'finding-listing must declare a severity column');

        self::assertSame(
            'select',
            trim((string)$severity->settings->filter),
            'severity column must declare <filter>select</filter> so the standard dropdown filter renders'
        );

        $options = $severity->settings->options ?? null;
        self::assertNotNull($options, 'severity column must declare an <options> source');
        self::assertSame(
            SeverityOptions::class,
            (string)$options['class'],
            'severity dropdown must be backed by SeverityOptions'
        );
    }

    public function testFindingListingDeclaresNoBespokeHeaderButtons(): void
    {
        // Regression guard for issue #106: no `<item name="buttons">`
        // block on the finding-listing argument. The replacement UX
        // is the standard column filter — anything reintroducing a
        // header button here is almost certainly a revert.
        $xml = simplexml_load_file(self::FINDING_LISTING);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        foreach ($xml->argument as $arg) {
            if ((string)$arg['name'] !== 'data') {
                continue;
            }
            foreach ($arg->item as $item) {
                self::assertNotSame(
                    'buttons',
                    (string)$item['name'],
                    'finding-listing must not declare a header-buttons block (issue #106)'
                );
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testFindingListingDoesNotBakeSeverityFilterDefaultIntoXml(): void
    {
        // AC: the default filter must be applied at the data-provider
        // layer, NOT via an XML `<filter>` default on the severity
        // column. Anyone re-introducing the latter would let admin
        // users persist a non-critical default via the bookmark UI,
        // which is the failure mode we're guarding against.
        $xml = simplexml_load_file(self::FINDING_LISTING);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        foreach ($xml->columns->column as $col) {
            if ((string)$col['name'] !== 'severity') {
                continue;
            }
            foreach ($col->settings->filter ?? [] as $filter) {
                foreach ($filter->attributes() as $attrName => $attrValue) {
                    self::assertNotSame(
                        'value',
                        (string)$attrName,
                        'severity column must not declare a hardcoded filter value'
                    );
                }
            }
        }
        // No explicit assertion if the loops complete — the absence of
        // a `value` attribute is the success path.
        $this->addToAssertionCount(1);
    }

    public function testFindingListingDeclaresRequiredColumns(): void
    {
        $xml = simplexml_load_file(self::FINDING_LISTING);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $columnsBlock = null;
        foreach ($xml->columns as $candidate) {
            if ((string)$candidate['name'] === 'ironcartscan_finding_listing_columns') {
                $columnsBlock = $candidate;
                break;
            }
        }
        self::assertNotNull($columnsBlock, 'finding-listing must declare a columns block');

        $names = $this->columnNames($columnsBlock);
        foreach (['check_id', 'severity', 'title', 'detail', 'created_at'] as $col) {
            self::assertContains($col, $names, "finding-listing must include column `{$col}`");
        }
    }

    public function testLayoutHandlesAttachUiComponents(): void
    {
        $pairs = [
            self::LAYOUT_INDEX => 'ironcartscan_run_listing',
            self::LAYOUT_VIEW => 'ironcartscan_finding_listing',
        ];
        foreach ($pairs as $path => $expected) {
            self::assertFileExists($path);
            $xml = simplexml_load_file($path);
            self::assertInstanceOf(SimpleXMLElement::class, $xml);

            $names = [];
            foreach ($xml->body->referenceContainer->uiComponent ?? [] as $ui) {
                $names[] = (string)$ui['name'];
            }
            self::assertContains(
                $expected,
                $names,
                "layout {$path} must attach uiComponent `{$expected}`"
            );
        }
    }

    public function testGlobalDiWiresCollectionsForBothDataSources(): void
    {
        // Issue #204: the CollectionFactory.collections registration MUST
        // live in GLOBAL etc/di.xml, not etc/adminhtml/di.xml. Core
        // registers all 43 of its grid data sources on the same argument
        // in global scope; the global->area merge is array_replace at the
        // argument level, so an adminhtml-scope copy wholesale-replaces
        // core's entries and breaks every core admin grid. This test pins
        // the registration to global scope so a revert surfaces here in
        // the unit cell rather than only on a real admin render.
        self::assertFileExists(self::GLOBAL_DI);
        $xml = simplexml_load_file(self::GLOBAL_DI);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        $type = null;
        foreach ($xml->type as $candidate) {
            if ((string)$candidate['name'] === UiCollectionFactory::class) {
                $type = $candidate;
                break;
            }
        }
        self::assertNotNull($type, 'etc/di.xml must wire CollectionFactory in global scope');

        $sources = [];
        foreach ($type->arguments->argument->item ?? [] as $item) {
            $sources[(string)$item['name']] = trim((string)$item);
        }

        self::assertArrayHasKey('ironcartscan_run_listing_data_source', $sources);
        self::assertSame(
            ScanRunCollection::class,
            $sources['ironcartscan_run_listing_data_source']
        );
        self::assertArrayHasKey('ironcartscan_finding_listing_data_source', $sources);
        self::assertSame(
            ScanFindingCollection::class,
            $sources['ironcartscan_finding_listing_data_source']
        );
    }

    public function testAdminhtmlDiDoesNotWireCollectionFactory(): void
    {
        // Issue #204 regression guard: the CollectionFactory.collections
        // block must NOT reappear in adminhtml scope (that is the bug).
        // The MessageList <type> block legitimately stays in this file.
        self::assertFileExists(self::ADMINHTML_DI);
        $xml = simplexml_load_file(self::ADMINHTML_DI);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        foreach ($xml->type as $candidate) {
            self::assertNotSame(
                UiCollectionFactory::class,
                (string)$candidate['name'],
                'etc/adminhtml/di.xml must NOT wire CollectionFactory — '
                . 'it belongs in global etc/di.xml (issue #204)'
            );
        }
        $this->addToAssertionCount(1);
    }

    /**
     * @return list<string>
     */
    private function columnNames(SimpleXMLElement $columns): array
    {
        $names = [];
        foreach ($columns->column as $col) {
            $names[] = (string)$col['name'];
        }
        return $names;
    }
}
