<?php

/**
 * IronCart_Scan — IC-922 unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\PwaStudio;

use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use IronCart\Scan\Check\PwaStudio\GraphQlQueryComplexityCheck;
use IronCart\Scan\Check\PwaStudio\PwaStudioDetector;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

final class GraphQlQueryComplexityCheckTest extends TestCase
{
    public function testReturnsNoFindingsWhenPwaNotDetected(): void
    {
        $check = new GraphQlQueryComplexityCheck(
            $this->detectorReturning(false),
            $this->createMock(ScopeConfigInterface::class)
        );
        self::assertSame([], $check->run());
    }

    public function testReturnsNoFindingsWhenLimitsAreSafe(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnMap([
            [GraphQlQueryComplexityCheck::CONFIG_MAX_DEPTH, null, null, '20'],
            [GraphQlQueryComplexityCheck::CONFIG_MAX_COMPLEXITY, null, null, '300'],
        ]);
        $check = new GraphQlQueryComplexityCheck(
            $this->detectorReturning(true),
            $config
        );
        self::assertSame([], $check->run());
    }

    public function testFlagsWhenDepthAboveCeiling(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnMap([
            [GraphQlQueryComplexityCheck::CONFIG_MAX_DEPTH, null, null, '500'],
            [GraphQlQueryComplexityCheck::CONFIG_MAX_COMPLEXITY, null, null, '300'],
        ]);
        $check = new GraphQlQueryComplexityCheck(
            $this->detectorReturning(true),
            $config
        );
        $findings = $check->run();
        self::assertCount(1, $findings);
        self::assertSame('IC-922', $findings[0]['id']);
        self::assertSame(Severity::MEDIUM, $findings[0]['severity']);
        $gaps = $findings[0]['evidence']['gaps'];
        self::assertCount(1, $gaps);
        self::assertSame(GraphQlQueryComplexityCheck::CONFIG_MAX_DEPTH, $gaps[0]['config_path']);
        self::assertSame('above_ceiling', $gaps[0]['reason']);
    }

    public function testFlagsBothWhenUnset(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn(null);
        $check = new GraphQlQueryComplexityCheck(
            $this->detectorReturning(true),
            $config
        );
        $findings = $check->run();
        self::assertCount(1, $findings);
        $gaps = $findings[0]['evidence']['gaps'];
        self::assertCount(2, $gaps);
        foreach ($gaps as $gap) {
            self::assertSame('unset_or_invalid', $gap['reason']);
        }
    }

    public function testNonNumericConfigIsTreatedAsUnset(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnMap([
            [GraphQlQueryComplexityCheck::CONFIG_MAX_DEPTH, null, null, 'unlimited'],
            [GraphQlQueryComplexityCheck::CONFIG_MAX_COMPLEXITY, null, null, '300'],
        ]);
        $check = new GraphQlQueryComplexityCheck(
            $this->detectorReturning(true),
            $config
        );
        $findings = $check->run();
        self::assertCount(1, $findings);
        $gaps = $findings[0]['evidence']['gaps'];
        self::assertCount(1, $gaps);
        self::assertSame('unset_or_invalid', $gaps[0]['reason']);
    }

    private function detectorReturning(bool $detected): PwaStudioDetector
    {
        return new class ($detected) extends PwaStudioDetector {
            public function __construct(private readonly bool $detected)
            {
                parent::__construct(new ComposerLockReader(null), null);
            }
            public function isDetected(): bool
            {
                return $this->detected;
            }
        };
    }
}
