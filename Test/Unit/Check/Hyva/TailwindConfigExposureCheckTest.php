<?php

/**
 * IronCart_Scan — IC-910 unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Hyva;

use IronCart\Scan\Check\Hyva\HyvaDetector;
use IronCart\Scan\Check\Hyva\TailwindConfigExposureCheck;
use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use IronCart\Scan\Report\Severity;
use Magento\Framework\Module\ModuleListInterface;
use PHPUnit\Framework\TestCase;

final class TailwindConfigExposureCheckTest extends TestCase
{
    private string $tmpRoot = '';

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'ironcart-scan-ic910-'
            . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
    }

    public function testReturnsNoFindingsWhenHyvaNotDetected(): void
    {
        $check = new TailwindConfigExposureCheck(
            $this->detectorReturning(false),
            $this->tmpRoot
        );
        self::assertSame([], $check->run());
    }

    public function testReturnsNoFindingsWhenStaticDirMissing(): void
    {
        $check = new TailwindConfigExposureCheck(
            $this->detectorReturning(true),
            $this->tmpRoot
        );
        self::assertSame([], $check->run());
    }

    public function testFlagsTailwindConfigUnderPubStatic(): void
    {
        $themeDir = $this->tmpRoot
            . '/pub/static/frontend/Hyva/default/en_US';
        mkdir($themeDir, 0o755, true);
        file_put_contents($themeDir . '/tailwind.config.js', "module.exports = {};\n");

        $check = new TailwindConfigExposureCheck(
            $this->detectorReturning(true),
            $this->tmpRoot
        );
        $findings = $check->run();

        self::assertCount(1, $findings);
        self::assertSame('IC-910', $findings[0]['id']);
        self::assertSame(Severity::MEDIUM, $findings[0]['severity']);
        $paths = $findings[0]['evidence']['exposed_paths'];
        self::assertCount(1, $paths);
        self::assertStringContainsString('tailwind.config.js', $paths[0]);
    }

    public function testFlagsNestedTailwindSubdirectory(): void
    {
        $tailwindDir = $this->tmpRoot
            . '/pub/static/frontend/Hyva/default/tailwind';
        mkdir($tailwindDir, 0o755, true);
        file_put_contents($tailwindDir . '/tailwind.source.css', "@tailwind base;\n");
        file_put_contents($tailwindDir . '/postcss.config.js', "module.exports = {};\n");

        $check = new TailwindConfigExposureCheck(
            $this->detectorReturning(true),
            $this->tmpRoot
        );
        $findings = $check->run();

        self::assertCount(1, $findings);
        $paths = $findings[0]['evidence']['exposed_paths'];
        self::assertCount(2, $paths);
    }

    public function testReturnsNoFindingsWhenNoBuildConfigsPresent(): void
    {
        $themeDir = $this->tmpRoot . '/pub/static/frontend/Hyva/default';
        mkdir($themeDir, 0o755, true);
        file_put_contents($themeDir . '/style.css', "body{}\n");

        $check = new TailwindConfigExposureCheck(
            $this->detectorReturning(true),
            $this->tmpRoot
        );
        self::assertSame([], $check->run());
    }

    private function detectorReturning(bool $detected): HyvaDetector
    {
        return new class ($detected) extends HyvaDetector {
            public function __construct(private readonly bool $detected)
            {
                parent::__construct(
                    $this->moduleList(),
                    new ComposerLockReader(null)
                );
            }
            public function isDetected(): bool
            {
                return $this->detected;
            }
            public function detect(): array
            {
                return [
                    'detected' => $this->detected,
                    'signals' => ['module' => $this->detected, 'composer' => false],
                    'hyva_packages' => [],
                ];
            }
            public function hyvaPackages(): array
            {
                return [];
            }
            private function moduleList(): ModuleListInterface
            {
                return new class implements ModuleListInterface {
                    public function getAll()
                    {
                        return [];
                    }
                    public function getOne($name)
                    {
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
            }
        };
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
