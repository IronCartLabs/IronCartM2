<?php

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\CodeSmell;

use IronCart\Scan\Check\CodeSmell\AppCodeWalker;
use IronCart\Scan\Check\Filesystem\MagentoRoot;
use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Temp-directory sandbox for the v2 code-smell check pack tests.
 *
 * Each test case builds a tiny synthetic Magento root under
 * `sys_get_temp_dir()`, writes one or more `app/code/Vendor/Module/...`
 * (and sometimes `vendor/Vendor/Lib/...`) PHP files, then runs the check
 * against the resulting tree. Files are wiped on `cleanup()`.
 *
 * Lives alongside the existing {@see \IronCart\Scan\Test\Unit\Check\Filesystem\FilesystemSandbox}
 * but kept separate so each pack's helper can evolve independently — the
 * filesystem-posture sandbox doesn't need an {@see AppCodeWalker}, and
 * the code-smell sandbox doesn't need the chmod plumbing.
 */
final class CodeSmellSandbox
{
    private string $root;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/ironcart-codesmell-' . bin2hex(random_bytes(6));
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
                // Skip parent constructor — tests only call getRoot().
            }

            public function getRoot(): string
            {
                return $this->sandboxRoot;
            }
        };

        return new MagentoRoot($directoryList);
    }

    public function walker(): AppCodeWalker
    {
        return new AppCodeWalker($this->magentoRoot());
    }

    /**
     * Write a synthetic module file. Paths are relative to the sandbox
     * root, so callers pass `app/code/Acme/Bad/Block/Pwn.php`.
     */
    public function writeFile(string $relative, string $contents): string
    {
        $absolute = $this->root . '/' . ltrim($relative, '/');
        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Failed to create ' . $dir);
        }
        file_put_contents($absolute, $contents);

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
