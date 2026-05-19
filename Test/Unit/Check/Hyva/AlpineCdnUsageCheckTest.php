<?php

/**
 * IronCart_Scan — IC-913 unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Hyva;

use IronCart\Scan\Check\Hyva\AlpineCdnUsageCheck;
use IronCart\Scan\Check\Hyva\HyvaDetector;
use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use IronCart\Scan\Report\Severity;
use Magento\Framework\Module\ModuleListInterface;
use PHPUnit\Framework\TestCase;

final class AlpineCdnUsageCheckTest extends TestCase
{
    private string $tmpRoot = '';

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'ironcart-scan-ic913-'
            . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot, 0o755, true);
        // composer.lock so the auto-root resolver short-circuits in
        // tests that don't pass an explicit root.
        file_put_contents($this->tmpRoot . '/composer.lock', '{}');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
    }

    public function testReturnsNoFindingsWhenHyvaNotDetected(): void
    {
        $check = new AlpineCdnUsageCheck(
            $this->detectorReturning(false),
            $this->tmpRoot
        );
        self::assertSame([], $check->run());
    }

    public function testReturnsNoFindingsWhenNoTemplatesPresent(): void
    {
        $check = new AlpineCdnUsageCheck(
            $this->detectorReturning(true),
            $this->tmpRoot
        );
        self::assertSame([], $check->run());
    }

    public function testFlagsJsdelivrAlpine(): void
    {
        $themeDir = $this->tmpRoot . '/app/design/frontend/Hyva/default/Magento_Theme/templates';
        mkdir($themeDir, 0o755, true);
        file_put_contents(
            $themeDir . '/header.phtml',
            '<html><head>'
            . '<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>'
            . '</head></html>'
        );

        $check = new AlpineCdnUsageCheck(
            $this->detectorReturning(true),
            $this->tmpRoot
        );
        $findings = $check->run();
        self::assertCount(1, $findings);
        self::assertSame('IC-913', $findings[0]['id']);
        self::assertSame(Severity::MEDIUM, $findings[0]['severity']);
        $matches = $findings[0]['evidence']['matches'];
        self::assertCount(1, $matches);
        self::assertStringContainsString('alpinejs', $matches[0]['url']);
        self::assertStringContainsString('header.phtml', $matches[0]['file']);
    }

    public function testFlagsUnpkgAlpinePerFile(): void
    {
        $themeDir = $this->tmpRoot . '/vendor/hyva-themes/magento2-default-theme/templates';
        mkdir($themeDir, 0o755, true);
        file_put_contents(
            $themeDir . '/a.phtml',
            '<script src="https://unpkg.com/alpinejs"></script>'
        );
        file_put_contents(
            $themeDir . '/b.phtml',
            '<script src="https://cdnjs.cloudflare.com/ajax/libs/alpinejs/3.13.0/cdn.min.js"></script>'
        );

        $check = new AlpineCdnUsageCheck(
            $this->detectorReturning(true),
            $this->tmpRoot
        );
        $findings = $check->run();
        self::assertCount(1, $findings);
        self::assertCount(2, $findings[0]['evidence']['matches']);
    }

    public function testIgnoresFirstPartyAndUnrelatedScripts(): void
    {
        $themeDir = $this->tmpRoot . '/app/design/frontend/Hyva/default';
        mkdir($themeDir, 0o755, true);
        file_put_contents(
            $themeDir . '/x.phtml',
            '<script src="/static/frontend/Hyva/default/en_US/alpine.js"></script>'
            . '<script src="https://cdn.jsdelivr.net/npm/jquery"></script>'
        );

        $check = new AlpineCdnUsageCheck(
            $this->detectorReturning(true),
            $this->tmpRoot
        );
        self::assertSame([], $check->run());
    }

    public function testIgnoresNonTemplateExtensions(): void
    {
        $themeDir = $this->tmpRoot . '/app/design/frontend/Hyva/default';
        mkdir($themeDir, 0o755, true);
        file_put_contents(
            $themeDir . '/notes.md',
            '<script src="https://cdn.jsdelivr.net/npm/alpinejs"></script>'
        );

        $check = new AlpineCdnUsageCheck(
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
