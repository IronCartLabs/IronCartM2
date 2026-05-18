<?php

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\CheckRegistry;
use IronCart\Scan\Check\DeprecationRegistry;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\CheckRegistry
 */
class CheckRegistryTest extends TestCase
{
    public function testRunAllConcatenatesFindingsInRegistrationOrder(): void
    {
        $a = $this->stubCheck([
            $this->finding('IC-A', Severity::HIGH, 'a1'),
        ]);
        $b = $this->stubCheck([]);
        $c = $this->stubCheck([
            $this->finding('IC-C', Severity::LOW, 'c1'),
            $this->finding('IC-C', Severity::LOW, 'c2'),
        ]);

        $registry = new CheckRegistry(['a' => $a, 'b' => $b, 'c' => $c]);

        $findings = $registry->runAll();

        self::assertCount(3, $findings);
        self::assertSame('a1', $findings[0]['title']);
        self::assertSame('c1', $findings[1]['title']);
        self::assertSame('c2', $findings[2]['title']);
    }

    public function testEmptyRegistryReturnsEmptyArray(): void
    {
        self::assertSame([], (new CheckRegistry())->runAll());
    }

    public function testDeprecatedChecksRunByDefault(): void
    {
        // v1.x default — issue #83 announce-before-remove: deprecated
        // checks still execute, the operator only sees a stderr notice.
        $deprecated = $this->stubCheck([$this->finding('IC-060', Severity::HIGH, 'cve')]);
        $live = $this->stubCheck([$this->finding('IC-001', Severity::HIGH, 'patch')]);

        $registry = new CheckRegistry(
            ['IC-060' => $deprecated, 'IC-001' => $live],
            new DeprecationRegistry()
        );

        $findings = $registry->runAll();

        self::assertCount(2, $findings);
        self::assertSame(['IC-060'], $registry->lastRunDeprecatedKeys());
    }

    public function testIncludeDeprecatedFalseSkipsDeprecatedChecks(): void
    {
        // Operator opted out — the deprecated check is never instantiated,
        // its run() is never called, and its findings never reach the
        // report. The non-deprecated check is unaffected.
        $deprecated = new class implements CheckInterface {
            public int $runCalls = 0;

            public function run(): array
            {
                $this->runCalls++;
                return [];
            }
        };
        $live = $this->stubCheck([$this->finding('IC-001', Severity::HIGH, 'patch')]);

        $registry = new CheckRegistry(
            ['IC-060' => $deprecated, 'IC-001' => $live],
            new DeprecationRegistry()
        );

        $findings = $registry->runAll(includeDeprecated: false);

        self::assertCount(1, $findings);
        self::assertSame('IC-001', $findings[0]['id']);
        self::assertSame(
            0,
            $deprecated->runCalls,
            'IC-060 check must NOT run when --include-deprecated=false'
        );
        self::assertSame(
            [],
            $registry->lastRunDeprecatedKeys(),
            'Skipped deprecated checks must not appear in the ran-list (otherwise '
            . 'ScanCommand would emit a stderr notice for a check that did not run)'
        );
    }

    public function testWithoutDeprecationRegistryFlagHasNoEffect(): void
    {
        // Backward-compatibility: legacy fixtures that don't wire the
        // DeprecationRegistry must keep their v0 behaviour. The
        // includeDeprecated flag becomes a no-op.
        $a = $this->stubCheck([$this->finding('IC-060', Severity::HIGH, 'a1')]);
        $registry = new CheckRegistry(['IC-060' => $a]);

        $findings = $registry->runAll(includeDeprecated: false);

        self::assertCount(1, $findings, 'No registry wired -> no filtering -> v0 behaviour preserved');
    }

    /**
     * @param list<array{id:string,title:string,severity:string,evidence:mixed,remediation_url:string}> $findings
     */
    private function stubCheck(array $findings): CheckInterface
    {
        return new class ($findings) implements CheckInterface {
            /**
             * @param list<array{id:string,title:string,severity:string,evidence:mixed,remediation_url:string}> $findings
             */
            public function __construct(private readonly array $findings)
            {
            }

            public function run(): array
            {
                return $this->findings;
            }
        };
    }

    /**
     * @return array{id:string,title:string,severity:string,evidence:mixed,remediation_url:string}
     */
    private function finding(string $id, string $severity, string $title): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'severity' => $severity,
            'evidence' => [],
            'remediation_url' => 'https://ironcart.dev',
        ];
    }
}
