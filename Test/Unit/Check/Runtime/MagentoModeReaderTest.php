<?php

/**
 * IronCart_Scan — MagentoModeReader unit tests.
 *
 * Pins both halves of the shared MAGE_MODE reader contract:
 *
 *   1. Happy path — return whatever {@see State::getMode()} returns verbatim.
 *   2. Throwable fallback — any exception inside `getMode()` resolves to
 *      {@see State::MODE_DEFAULT}.
 *
 * Four checks (IC-020, IC-024, IC-084, IC-921) depend on the fallback
 * branch so a bootstrap-phase `LogicException` does not cascade into a
 * scan-wide failure. Keep both branches green here before touching the
 * reader.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Runtime;

use IronCart\Scan\Check\Runtime\MagentoModeReader;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

final class MagentoModeReaderTest extends TestCase
{
    public function testReturnsModeFromAppState(): void
    {
        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn(State::MODE_PRODUCTION);

        $reader = new MagentoModeReader($state);

        $this->assertSame(State::MODE_PRODUCTION, $reader->mode());
    }

    public function testReturnsDeveloperModeVerbatim(): void
    {
        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn(State::MODE_DEVELOPER);

        $reader = new MagentoModeReader($state);

        $this->assertSame(State::MODE_DEVELOPER, $reader->mode());
    }

    public function testThrowableFallsBackToDefaultMode(): void
    {
        $state = $this->createMock(State::class);
        $state->method('getMode')->willThrowException(new \LogicException('not ready'));

        $reader = new MagentoModeReader($state);

        $this->assertSame(State::MODE_DEFAULT, $reader->mode());
    }

    public function testRuntimeExceptionAlsoFallsBackToDefault(): void
    {
        // Pin that the catch is `Throwable`, not a narrower type — a
        // RuntimeException from deep inside the area-loader machinery
        // should still resolve to MODE_DEFAULT rather than escape.
        $state = $this->createMock(State::class);
        $state->method('getMode')->willThrowException(new \RuntimeException('boom'));

        $reader = new MagentoModeReader($state);

        $this->assertSame(State::MODE_DEFAULT, $reader->mode());
    }
}
