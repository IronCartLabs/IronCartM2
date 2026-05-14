<?php

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Filesystem;

use IronCart\Scan\Check\Filesystem\MagentoRoot;
use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Tiny helper for the filesystem check tests — builds a throwaway sandbox
 * directory under `sys_get_temp_dir()`, returns a {@see MagentoRoot} that
 * points at it, and tracks the directory so it can be cleaned up.
 */
final class FilesystemSandbox
{
    private string $root;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/ironcart-scan-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root, 0o755, true) && !is_dir($this->root)) {
            throw new \RuntimeException('Failed to create sandbox at ' . $this->root);
        }
    }

    public function root(): string
    {
        return $this->root;
    }

    public function magentoRoot(): MagentoRoot
    {
        $directoryList = new class ($this->root) extends DirectoryList {
            public function __construct(private readonly string $sandboxRoot)
            {
                // Intentionally skip the parent constructor — tests only need getRoot().
            }

            public function getRoot(): string
            {
                return $this->sandboxRoot;
            }
        };

        return new MagentoRoot($directoryList);
    }

    public function writeFile(string $relative, string $contents, int $mode = 0o644): string
    {
        $absolute = $this->root . '/' . ltrim($relative, '/');
        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Failed to create ' . $dir);
        }
        file_put_contents($absolute, $contents);
        chmod($absolute, $mode);

        return $absolute;
    }

    public function makeDir(string $relative, int $mode = 0o755): string
    {
        $absolute = $this->root . '/' . ltrim($relative, '/');
        if (!is_dir($absolute) && !mkdir($absolute, $mode, true) && !is_dir($absolute)) {
            throw new \RuntimeException('Failed to create ' . $absolute);
        }
        chmod($absolute, $mode);

        return $absolute;
    }

    public function cleanup(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($this->root);
    }
}
