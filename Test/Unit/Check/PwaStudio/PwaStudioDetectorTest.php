<?php

/**
 * IronCart_Scan — PwaStudioDetector unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\PwaStudio;

use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use IronCart\Scan\Check\PwaStudio\PwaStudioDetector;
use PHPUnit\Framework\TestCase;

final class PwaStudioDetectorTest extends TestCase
{
    private string $tmpRoot = '';

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'ironcart-scan-ic920-'
            . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
    }

    public function testReturnsFalseWhenNoSignalsPresent(): void
    {
        $detector = new PwaStudioDetector(
            $this->lockReaderWith([]),
            $this->tmpRoot
        );
        $result = $detector->detect();
        self::assertFalse($result['detected']);
        self::assertFalse($result['signals']['composer']);
        self::assertFalse($result['signals']['npm']);
        self::assertFalse($result['signals']['filesystem']);
    }

    public function testDetectsViaComposerPackage(): void
    {
        $detector = new PwaStudioDetector(
            $this->lockReaderWith(['magento/pwa' => '1.0.0']),
            $this->tmpRoot
        );
        $result = $detector->detect();
        self::assertTrue($result['detected']);
        self::assertTrue($result['signals']['composer']);
        self::assertSame(['magento/pwa' => '1.0.0'], $result['composer_packages']);
    }

    public function testDetectsViaPackageJsonDependency(): void
    {
        file_put_contents(
            $this->tmpRoot . '/package.json',
            json_encode([
                'name' => 'merchant-storefront',
                'dependencies' => [
                    '@magento/venia-ui' => '^14.0.0',
                    'react' => '^18.0.0',
                ],
            ], JSON_THROW_ON_ERROR)
        );
        $detector = new PwaStudioDetector(
            $this->lockReaderWith([]),
            $this->tmpRoot
        );
        $result = $detector->detect();
        self::assertTrue($result['detected']);
        self::assertTrue($result['signals']['npm']);
        self::assertSame(['@magento/venia-ui' => '^14.0.0'], $result['npm_packages']);
    }

    public function testDetectsViaDevDependencies(): void
    {
        file_put_contents(
            $this->tmpRoot . '/package.json',
            json_encode([
                'devDependencies' => [
                    '@magento/pwa-studio' => '^14.0.0',
                ],
            ], JSON_THROW_ON_ERROR)
        );
        $detector = new PwaStudioDetector(
            $this->lockReaderWith([]),
            $this->tmpRoot
        );
        self::assertTrue($detector->isDetected());
    }

    public function testDetectsViaFilesystemMarker(): void
    {
        mkdir($this->tmpRoot . '/packages/venia-concept', 0o755, true);
        $detector = new PwaStudioDetector(
            $this->lockReaderWith([]),
            $this->tmpRoot
        );
        $result = $detector->detect();
        self::assertTrue($result['detected']);
        self::assertTrue($result['signals']['filesystem']);
    }

    public function testMalformedPackageJsonIsIgnored(): void
    {
        file_put_contents($this->tmpRoot . '/package.json', '{ not json');
        $detector = new PwaStudioDetector(
            $this->lockReaderWith([]),
            $this->tmpRoot
        );
        self::assertFalse($detector->isDetected());
    }

    public function testDetectIsMemoised(): void
    {
        $callCount = 0;
        $lockReader = new class ($callCount) extends ComposerLockReader {
            public function __construct(private int &$callCount)
            {
                parent::__construct(null);
            }
            public function packages(): array
            {
                $this->callCount++;
                return [];
            }
        };
        $detector = new PwaStudioDetector($lockReader, $this->tmpRoot);
        $detector->detect();
        $detector->detect();
        $detector->isDetected();
        self::assertSame(1, $callCount, 'ComposerLockReader::packages() should be called once and memoised');
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
