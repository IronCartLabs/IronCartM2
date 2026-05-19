<?php

/**
 * IronCart_Scan — IC-921 unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\PwaStudio;

use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use IronCart\Scan\Check\PwaStudio\GraphQlIntrospectionCheck;
use IronCart\Scan\Check\PwaStudio\PwaStudioDetector;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

final class GraphQlIntrospectionCheckTest extends TestCase
{
    public function testReturnsNoFindingsWhenPwaNotDetected(): void
    {
        $check = new GraphQlIntrospectionCheck(
            $this->detectorReturning(false),
            $this->createMock(ScopeConfigInterface::class),
            $this->stateReturning(State::MODE_PRODUCTION)
        );
        self::assertSame([], $check->run());
    }

    public function testReturnsNoFindingsInDeveloperMode(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $check = new GraphQlIntrospectionCheck(
            $this->detectorReturning(true),
            $config,
            $this->stateReturning(State::MODE_DEVELOPER)
        );
        self::assertSame([], $check->run());
    }

    public function testReturnsNoFindingsWhenIntrospectionDisabled(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')
            ->with(GraphQlIntrospectionCheck::CONFIG_DISABLE_INTROSPECTION)
            ->willReturn('1');

        $check = new GraphQlIntrospectionCheck(
            $this->detectorReturning(true),
            $config,
            $this->stateReturning(State::MODE_PRODUCTION)
        );
        self::assertSame([], $check->run());
    }

    public function testFlagsWhenIntrospectionEnabledInProduction(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')
            ->with(GraphQlIntrospectionCheck::CONFIG_DISABLE_INTROSPECTION)
            ->willReturn('0');

        $check = new GraphQlIntrospectionCheck(
            $this->detectorReturning(true),
            $config,
            $this->stateReturning(State::MODE_PRODUCTION)
        );
        $findings = $check->run();
        self::assertCount(1, $findings);
        self::assertSame('IC-921', $findings[0]['id']);
        self::assertSame(Severity::MEDIUM, $findings[0]['severity']);
        self::assertSame(State::MODE_PRODUCTION, $findings[0]['evidence']['mage_mode']);
    }

    public function testNullConfigTreatedAsEnabled(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn(null);

        $check = new GraphQlIntrospectionCheck(
            $this->detectorReturning(true),
            $config,
            $this->stateReturning(State::MODE_PRODUCTION)
        );
        self::assertCount(1, $check->run());
    }

    public function testStateExceptionFallsBackToDefaultModeWhichSkips(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $state = $this->createMock(State::class);
        $state->method('getMode')->willThrowException(new \LogicException('not ready'));

        $check = new GraphQlIntrospectionCheck(
            $this->detectorReturning(true),
            $config,
            $state
        );
        self::assertSame([], $check->run());
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
            public function detect(): array
            {
                return [
                    'detected' => $this->detected,
                    'signals' => [
                        'composer' => $this->detected,
                        'npm' => false,
                        'filesystem' => false,
                    ],
                    'composer_packages' => [],
                    'npm_packages' => [],
                ];
            }
        };
    }

    private function stateReturning(string $mode): State
    {
        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn($mode);
        return $state;
    }
}
