<?php

/**
 * IronCart_Scan — IC-923 unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\PwaStudio;

use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use IronCart\Scan\Check\PwaStudio\GraphQlCorsWildcardCheck;
use IronCart\Scan\Check\PwaStudio\PwaStudioDetector;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

final class GraphQlCorsWildcardCheckTest extends TestCase
{
    public function testReturnsNoFindingsWhenPwaNotDetected(): void
    {
        $check = new GraphQlCorsWildcardCheck(
            $this->detectorReturning(false),
            $this->createMock(ScopeConfigInterface::class)
        );
        self::assertSame([], $check->run());
    }

    public function testReturnsNoFindingsWhenUnset(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn(null);
        $check = new GraphQlCorsWildcardCheck(
            $this->detectorReturning(true),
            $config
        );
        self::assertSame([], $check->run());
    }

    public function testReturnsNoFindingsForExplicitOriginAllowlist(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn(
            'https://shop.example.com,https://staging.example.com'
        );
        $check = new GraphQlCorsWildcardCheck(
            $this->detectorReturning(true),
            $config
        );
        self::assertSame([], $check->run());
    }

    public function testFlagsBareWildcard(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn('*');
        $check = new GraphQlCorsWildcardCheck(
            $this->detectorReturning(true),
            $config
        );
        $findings = $check->run();
        self::assertCount(1, $findings);
        self::assertSame('IC-923', $findings[0]['id']);
        self::assertSame(Severity::HIGH, $findings[0]['severity']);
        self::assertSame(['*'], $findings[0]['evidence']['wildcard_entries']);
    }

    public function testFlagsSubdomainWildcardEntry(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn(
            'https://shop.example.com,*.example.com'
        );
        $check = new GraphQlCorsWildcardCheck(
            $this->detectorReturning(true),
            $config
        );
        $findings = $check->run();
        self::assertCount(1, $findings);
        self::assertSame(['*.example.com'], $findings[0]['evidence']['wildcard_entries']);
    }

    public function testFlagsLiteralNullOrigin(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn('null');
        $check = new GraphQlCorsWildcardCheck(
            $this->detectorReturning(true),
            $config
        );
        $findings = $check->run();
        self::assertCount(1, $findings);
        self::assertSame(['null'], $findings[0]['evidence']['wildcard_entries']);
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
