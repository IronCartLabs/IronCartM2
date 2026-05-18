<?php

/**
 * IronCart_Scan — HyvaDetector unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Hyva;

use IronCart\Scan\Check\Hyva\HyvaDetector;
use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use Magento\Framework\Module\ModuleListInterface;
use PHPUnit\Framework\TestCase;

final class HyvaDetectorTest extends TestCase
{
    public function testReturnsFalseWhenNeitherSignalPresent(): void
    {
        $detector = new HyvaDetector(
            $this->moduleListReturning(null),
            $this->lockReaderWith([])
        );

        $result = $detector->detect();
        self::assertFalse($result['detected']);
        self::assertFalse($result['signals']['module']);
        self::assertFalse($result['signals']['composer']);
        self::assertSame([], $result['hyva_packages']);
    }

    public function testReturnsTrueWhenModuleListReportsHyvaTheme(): void
    {
        $detector = new HyvaDetector(
            $this->moduleListReturning(['name' => 'Hyva_Theme', 'setup_version' => '1.3.6']),
            $this->lockReaderWith([])
        );

        $result = $detector->detect();
        self::assertTrue($result['detected']);
        self::assertTrue($result['signals']['module']);
        self::assertFalse($result['signals']['composer']);
    }

    public function testReturnsTrueWhenComposerLockMentionsHyvaPackages(): void
    {
        $detector = new HyvaDetector(
            $this->moduleListReturning(null),
            $this->lockReaderWith([
                'hyva-themes/magento2-default-theme' => '1.3.6',
                'hyva-themes/magento2-hyva-checkout' => '1.1.16',
                'magento/framework' => '103.0.7',
            ])
        );

        $result = $detector->detect();
        self::assertTrue($result['detected']);
        self::assertFalse($result['signals']['module']);
        self::assertTrue($result['signals']['composer']);
        self::assertSame([
            'hyva-themes/magento2-default-theme' => '1.3.6',
            'hyva-themes/magento2-hyva-checkout' => '1.1.16',
        ], $result['hyva_packages']);
    }

    public function testDetectIsMemoised(): void
    {
        $callCount = 0;
        $moduleList = new class ($callCount) implements ModuleListInterface {
            public function __construct(private int &$callCount)
            {
            }
            public function getAll()
            {
                return [];
            }
            public function getOne($name)
            {
                $this->callCount++;
                return null;
            }
            public function getNames()
            {
                return [];
            }
            public function has($name)
            {
                return false;
            }
        };

        $detector = new HyvaDetector($moduleList, $this->lockReaderWith([]));
        $detector->detect();
        $detector->detect();
        $detector->isDetected();
        self::assertSame(1, $callCount, 'ModuleListInterface::getOne() should be called once and memoised');
    }

    public function testResetReclearsCachedResult(): void
    {
        $detector = new HyvaDetector(
            $this->moduleListReturning(null),
            $this->lockReaderWith([])
        );
        self::assertFalse($detector->isDetected());
        $detector->reset();
        // Same inputs, same answer — but the cache is now cold.
        self::assertFalse($detector->isDetected());
    }

    /**
     * @param array<string,mixed>|null $module
     */
    private function moduleListReturning(?array $module): ModuleListInterface
    {
        return new class ($module) implements ModuleListInterface {
            /** @param array<string,mixed>|null $module */
            public function __construct(private readonly ?array $module)
            {
            }
            public function getAll()
            {
                return [];
            }
            public function getOne($name)
            {
                return $this->module;
            }
            public function getNames()
            {
                return [];
            }
            public function has($name)
            {
                return $this->module !== null;
            }
        };
    }

    /**
     * @param array<string,string> $packages
     */
    private function lockReaderWith(array $packages): ComposerLockReader
    {
        return new class ($packages) extends ComposerLockReader {
            /** @param array<string,string> $packages */
            public function __construct(private readonly array $packages)
            {
                parent::__construct(null);
            }
            public function packages(): array
            {
                return $this->packages;
            }
        };
    }
}
