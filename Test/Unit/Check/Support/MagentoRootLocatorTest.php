<?php

/**
 * IronCart_Scan — MagentoRootLocator unit tests.
 *
 * Covers the four scenarios spelled out in #186:
 *   - marker in start dir,
 *   - marker N levels up,
 *   - depth cap reached without hitting the marker,
 *   - filesystem root reached (`dirname($dir) === $dir`).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Support;

use IronCart\Scan\Check\Support\MagentoRootLocator;
use PHPUnit\Framework\TestCase;

final class MagentoRootLocatorTest extends TestCase
{
    private string $tmpRoot = '';

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'ironcart-scan-locator-'
            . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
    }

    public function testLocatesMarkerInStartDir(): void
    {
        file_put_contents($this->tmpRoot . '/composer.lock', "{}\n");

        self::assertSame(
            $this->tmpRoot,
            MagentoRootLocator::locate($this->tmpRoot)
        );
    }

    public function testLocatesMarkerSeveralLevelsUp(): void
    {
        file_put_contents($this->tmpRoot . '/composer.lock', "{}\n");
        $deep = $this->tmpRoot . '/app/code/IronCart/Scan/Check/PatchLevel';
        mkdir($deep, 0o755, true);

        self::assertSame(
            $this->tmpRoot,
            MagentoRootLocator::locate($deep)
        );
    }

    public function testReturnsNullWhenDepthCapHitBeforeMarker(): void
    {
        // Marker placed at the tmp root, but the start dir sits 12 levels
        // below it — deeper than the default 10-level cap.
        file_put_contents($this->tmpRoot . '/composer.lock', "{}\n");
        $deep = $this->tmpRoot
            . '/l1/l2/l3/l4/l5/l6/l7/l8/l9/l10/l11/l12';
        mkdir($deep, 0o755, true);

        self::assertNull(MagentoRootLocator::locate($deep));
    }

    public function testReturnsNullWhenFilesystemRootReachedWithoutMarker(): void
    {
        // Use a marker filename that cannot possibly exist anywhere on the
        // host so the walk is forced to terminate via the
        // `dirname($dir) === $dir` filesystem-root break rather than via
        // the depth cap. A high depth (1024) guarantees the cap is not the
        // termination cause on any realistic directory layout.
        $unique = 'ironcart-locator-no-such-marker-'
            . bin2hex(random_bytes(8))
            . '.lock';

        self::assertNull(
            MagentoRootLocator::locate($this->tmpRoot, $unique, 1024)
        );
    }

    public function testCustomMarkerIsHonoured(): void
    {
        // Sanity: the helper is generic over the marker filename. Used by
        // the three callers via DEFAULT_MARKER, but parameterising it
        // future-proofs the helper if conventions ever shift.
        file_put_contents($this->tmpRoot . '/MARKER.txt', "x\n");

        self::assertSame(
            $this->tmpRoot,
            MagentoRootLocator::locate($this->tmpRoot, 'MARKER.txt')
        );
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
