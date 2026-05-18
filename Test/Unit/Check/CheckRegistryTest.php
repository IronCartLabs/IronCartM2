<?php

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\CheckRegistry;
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
