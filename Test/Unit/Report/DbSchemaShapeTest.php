<?php

/**
 * IronCart_Scan — db_schema.xml structural smoke test.
 *
 * Pins the declarative schema for the v1 persistence tables so an
 * accidental rename / FK removal / index drop surfaces as a failing test
 * rather than a silent migration drift. Runs in the Report testsuite
 * (Test/Unit/Report) because that is the only Magento-free slice the
 * unit-CI cell exercises (see .github/workflows/ci.yml — the unit job
 * strips magento/framework before composer install, which means tests
 * under Test/Unit/Check/** etc. cannot be loaded).
 *
 * The real Magento "open the resource model and round-trip a row" smoke
 * runs in the integration job that boots the sandbox and applies
 * `bin/magento setup:upgrade`. See Test/Unit/Db/SchemaRoundtripTest.php
 * for that variant — it is autoloaded only inside the sandbox.
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
class DbSchemaShapeTest extends TestCase
{
    private const SCHEMA_PATH = __DIR__ . '/../../../etc/db_schema.xml';
    private const WHITELIST_PATH = __DIR__ . '/../../../etc/db_schema_whitelist.json';

    public function testSchemaFileExistsAndIsParseable(): void
    {
        self::assertFileExists(self::SCHEMA_PATH, 'etc/db_schema.xml must exist');

        $xml = simplexml_load_file(self::SCHEMA_PATH);
        self::assertInstanceOf(SimpleXMLElement::class, $xml, 'db_schema.xml must parse');
        self::assertSame('schema', $xml->getName(), 'root element must be <schema>');
    }

    public function testScanRunTableHasRequiredColumns(): void
    {
        $table = $this->table('ironcart_scan_run');
        $columns = $this->columnNames($table);

        // AC: entity_id (PK), status, started_at, finished_at (nullable),
        // triggered_by, summary_json, created_at, updated_at.
        $expected = [
            'entity_id',
            'status',
            'triggered_by',
            'started_at',
            'finished_at',
            'summary_json',
            'created_at',
            'updated_at',
        ];
        foreach ($expected as $name) {
            self::assertContains(
                $name,
                $columns,
                "ironcart_scan_run must declare column '{$name}'"
            );
        }
    }

    public function testScanRunFinishedAtIsNullable(): void
    {
        $col = $this->column('ironcart_scan_run', 'finished_at');
        self::assertSame('true', (string)$col['nullable'], 'finished_at must be nullable');
    }

    public function testScanRunHasPrimaryKeyOnEntityId(): void
    {
        $table = $this->table('ironcart_scan_run');
        $pk = null;
        foreach ($table->constraint as $c) {
            if ($this->xsiType($c) === 'primary' || (string)$c['referenceId'] === 'PRIMARY') {
                $pk = $c;
                break;
            }
        }
        self::assertNotNull($pk, 'ironcart_scan_run must declare a PRIMARY constraint');
        self::assertSame('entity_id', (string)$pk->column['name']);
    }

    public function testScanRunHasStatusStartedAtIndex(): void
    {
        $indexes = $this->indexNames($this->table('ironcart_scan_run'));
        self::assertContains(
            'IRONCART_SCAN_RUN_STATUS_STARTED_AT',
            $indexes,
            'AC: index on (status, started_at) for run-list filtering'
        );
    }

    public function testScanFindingTableHasRequiredColumns(): void
    {
        $table = $this->table('ironcart_scan_finding');
        $columns = $this->columnNames($table);

        $expected = [
            'entity_id',
            'scan_run_id',
            'check_id',
            'severity',
            'title',
            'detail',
            'evidence_json',
            'created_at',
        ];
        foreach ($expected as $name) {
            self::assertContains(
                $name,
                $columns,
                "ironcart_scan_finding must declare column '{$name}'"
            );
        }
    }

    public function testScanFindingHasCascadingForeignKeyToRun(): void
    {
        $table = $this->table('ironcart_scan_finding');
        $fk = null;
        foreach ($table->constraint as $c) {
            if ($this->xsiType($c) === 'foreign') {
                $fk = $c;
                break;
            }
        }
        self::assertNotNull($fk, 'ironcart_scan_finding must declare a foreign-key constraint');
        self::assertSame('scan_run_id', (string)$fk['column']);
        self::assertSame('ironcart_scan_run', (string)$fk['referenceTable']);
        self::assertSame('entity_id', (string)$fk['referenceColumn']);
        self::assertSame('CASCADE', (string)$fk['onDelete']);
    }

    /**
     * SimpleXML stores `xsi:type` under the XSI namespace, not as a plain
     * attribute. Reading `$node['type']` returns null even though the XML
     * source has `xsi:type="foreign"`. We need the namespaced accessor.
     */
    private function xsiType(SimpleXMLElement $node): string
    {
        $attrs = $node->attributes('http://www.w3.org/2001/XMLSchema-instance');
        return $attrs === null ? '' : (string)$attrs['type'];
    }

    public function testScanFindingHasRunSeverityAndCheckIdIndexes(): void
    {
        $indexes = $this->indexNames($this->table('ironcart_scan_finding'));
        self::assertContains(
            'IRONCART_SCAN_FINDING_SCAN_RUN_ID_SEVERITY',
            $indexes,
            'AC: index on (scan_run_id, severity)'
        );
        self::assertContains(
            'IRONCART_SCAN_FINDING_CHECK_ID',
            $indexes,
            'AC: index on (check_id)'
        );
    }

    public function testWhitelistJsonExistsAndCoversBothTables(): void
    {
        self::assertFileExists(self::WHITELIST_PATH, 'etc/db_schema_whitelist.json must exist');

        $whitelist = json_decode((string)file_get_contents(self::WHITELIST_PATH), true);
        self::assertIsArray($whitelist, 'whitelist must be a JSON object');
        self::assertArrayHasKey('ironcart_scan_run', $whitelist);
        self::assertArrayHasKey('ironcart_scan_finding', $whitelist);

        // Whitelist must enumerate every column declared in db_schema.xml —
        // a drift here is what causes `setup:upgrade` to refuse to drop the
        // column on rollback.
        foreach (['ironcart_scan_run', 'ironcart_scan_finding'] as $tableName) {
            $declared = $this->columnNames($this->table($tableName));
            $listed = array_keys($whitelist[$tableName]['column'] ?? []);
            sort($declared);
            sort($listed);
            self::assertSame(
                $declared,
                $listed,
                "whitelist columns for {$tableName} must match db_schema.xml exactly"
            );
        }
    }

    private function table(string $name): SimpleXMLElement
    {
        $xml = simplexml_load_file(self::SCHEMA_PATH);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);
        foreach ($xml->table as $t) {
            if ((string)$t['name'] === $name) {
                return $t;
            }
        }
        self::fail("table '{$name}' not declared in db_schema.xml");
    }

    private function column(string $tableName, string $columnName): SimpleXMLElement
    {
        foreach ($this->table($tableName)->column as $col) {
            if ((string)$col['name'] === $columnName) {
                return $col;
            }
        }
        self::fail("column '{$columnName}' not declared on table '{$tableName}'");
    }

    /**
     * @return list<string>
     */
    private function columnNames(SimpleXMLElement $table): array
    {
        $names = [];
        foreach ($table->column as $col) {
            $names[] = (string)$col['name'];
        }
        return $names;
    }

    /**
     * @return list<string>
     */
    private function indexNames(SimpleXMLElement $table): array
    {
        $names = [];
        foreach ($table->index as $idx) {
            $names[] = (string)$idx['referenceId'];
        }
        return $names;
    }
}
