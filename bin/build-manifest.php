<?php

/**
 * IronCart_Scan — manifest generator for IC-070.
 *
 * Clones a shallow checkout of `magento/magento2` at the requested tag,
 * walks the resulting tree, computes SHA-256 for every file, and writes
 * `etc/manifests/magento-core-community-<version>.json` in the schema the
 * runtime check expects.
 *
 * Usage (via the `manifests` Makefile target):
 *
 *     php bin/build-manifest.php --version=2.4.7-p5
 *     php bin/build-manifest.php --version=2.4.7-p5 --output=/tmp/manifest.json
 *
 * The script makes outbound network calls (git clone over HTTPS); it is a
 * BUILD-TIME tool only and is NOT invoked from the runtime scanner. See
 * docs/manifests.md for the full procedure and the supported-version list.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

const MAGENTO_SOURCE_URL = 'https://github.com/magento/magento2.git';
const SCHEMA_VERSION = 'v0';
const SUPPORTED_EDITION = 'community';

main($argv);

function main(array $argv): void
{
    $options = parseOptions(array_slice($argv, 1));

    if ($options['help']) {
        printUsage();
        exit(0);
    }
    if ($options['version'] === '') {
        fwrite(STDERR, "ERROR: --version=<tag> is required\n\n");
        printUsage();
        exit(2);
    }

    $version = $options['version'];
    $outputPath = $options['output'] !== ''
        ? $options['output']
        : dirname(__DIR__) . '/etc/manifests/magento-core-' . SUPPORTED_EDITION . '-' . $version . '.json';

    fwrite(STDOUT, "Building manifest for magento/magento2@{$version}...\n");

    $workdir = sys_get_temp_dir() . '/ironcart-manifest-' . bin2hex(random_bytes(6));
    if (!mkdir($workdir, 0o755, true)) {
        fwrite(STDERR, "ERROR: failed to create workdir {$workdir}\n");
        exit(1);
    }

    try {
        cloneShallow($version, $workdir);
        $entries = walkAndHash($workdir);
        writeManifest($outputPath, $version, $workdir, $entries);
        fwrite(STDOUT, "Wrote " . count($entries) . " entries to {$outputPath}\n");
    } finally {
        recursiveDelete($workdir);
    }
}

function parseOptions(array $args): array
{
    $opts = ['version' => '', 'output' => '', 'help' => false];
    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $opts['help'] = true;
        } elseif (str_starts_with($arg, '--version=')) {
            $opts['version'] = substr($arg, strlen('--version='));
        } elseif (str_starts_with($arg, '--output=')) {
            $opts['output'] = substr($arg, strlen('--output='));
        } else {
            fwrite(STDERR, "WARN: ignoring unknown argument {$arg}\n");
        }
    }
    return $opts;
}

function printUsage(): void
{
    fwrite(STDOUT, <<<USAGE
Usage: php bin/build-manifest.php --version=<tag> [--output=<path>]

  --version=<tag>   Magento tag to clone (e.g. 2.4.7-p5). Required.
  --output=<path>   Override the default output path
                    (etc/manifests/magento-core-community-<version>.json).
  -h, --help        Show this message.

Requires `git` on PATH. Network access is required at build time.

USAGE);
}

function cloneShallow(string $tag, string $workdir): void
{
    $url = MAGENTO_SOURCE_URL;
    // Defence-in-depth — tag comes from CLI, never from the runtime check.
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $tag)) {
        fwrite(STDERR, "ERROR: refusing to clone tag with unexpected characters: {$tag}\n");
        exit(1);
    }
    $cmd = sprintf(
        'git clone --depth 1 --branch %s %s %s',
        escapeshellarg($tag),
        escapeshellarg($url),
        escapeshellarg($workdir)
    );
    fwrite(STDOUT, "  $cmd\n");
    passthru($cmd, $exit);
    if ($exit !== 0) {
        fwrite(STDERR, "ERROR: git clone exited with status {$exit}\n");
        exit(1);
    }
}

/**
 * @return array<string,string> relative path => sha256 hex
 */
function walkAndHash(string $root): array
{
    $entries = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
            static function (SplFileInfo $current): bool {
                // Skip the .git directory entirely — irrelevant to merchant
                // installs (composer create-project does not leave it).
                return $current->getFilename() !== '.git';
            }
        )
    );

    /** @var SplFileInfo $info */
    foreach ($iterator as $info) {
        if (!$info->isFile()) {
            continue;
        }
        $absolute = $info->getPathname();
        $relative = ltrim(substr($absolute, strlen($root)), '/\\');
        $relative = str_replace('\\', '/', $relative);
        $hash = hash_file('sha256', $absolute);
        if ($hash === false) {
            fwrite(STDERR, "WARN: failed to hash {$relative}, skipping\n");
            continue;
        }
        $entries[$relative] = $hash;
    }

    ksort($entries);
    return $entries;
}

/**
 * @param array<string,string> $entries
 */
function writeManifest(string $outputPath, string $version, string $sourceRoot, array $entries): void
{
    $dir = dirname($outputPath);
    if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
        fwrite(STDERR, "ERROR: failed to create {$dir}\n");
        exit(1);
    }
    $payload = [
        'schema_version' => SCHEMA_VERSION,
        'edition' => SUPPORTED_EDITION,
        'version' => $version,
        'source' => MAGENTO_SOURCE_URL,
        'source_ref' => $version,
        'generated_at' => gmdate('Y-m-d'),
        'algorithm' => 'sha256',
        'entries' => $entries,
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        fwrite(STDERR, "ERROR: failed to encode manifest as JSON\n");
        exit(1);
    }
    if (file_put_contents($outputPath, $json . "\n") === false) {
        fwrite(STDERR, "ERROR: failed to write {$outputPath}\n");
        exit(1);
    }
}

function recursiveDelete(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    /** @var SplFileInfo $item */
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @chmod($item->getPathname(), 0o644);
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}
