<?php

/**
 * IronCart_Scan — composer-lock manifest generator for IC-072.
 *
 * Runs `composer create-project --no-interaction --no-install
 * magento/project-community-edition:<version>` into a tmpdir, parses the
 * resulting `composer.lock`, and writes
 * `etc/manifests/composer-sha1-community-<version>.json` in the schema the
 * runtime check expects.
 *
 * Usage (via the `composer-manifests` Makefile target):
 *
 *     php bin/build-composer-manifest.php --version=2.4.7-p5
 *     php bin/build-composer-manifest.php --version=2.4.7-p5 --output=/tmp/manifest.json
 *
 * The script makes outbound network calls (composer resolves against
 * repo.magento.com); it is a BUILD-TIME tool only and is NOT invoked from
 * the runtime scanner.
 *
 * `--no-install` is passed because we only need the lockfile — no
 * `vendor/` extraction. This both speeds the build up and avoids needing
 * the full extension set that an install would require.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

const PROJECT_PACKAGE = 'magento/project-community-edition';
const SCHEMA_VERSION = 'v0';
const SUPPORTED_EDITION = 'community';
const ALGORITHM = 'sha1';

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
        : dirname(__DIR__) . '/etc/manifests/composer-sha1-' . SUPPORTED_EDITION . '-' . $version . '.json';

    fwrite(STDOUT, "Building composer-sha1 manifest for " . PROJECT_PACKAGE . ":{$version}...\n");

    $workdir = sys_get_temp_dir() . '/ironcart-composer-manifest-' . bin2hex(random_bytes(6));
    if (!mkdir($workdir, 0o755, true)) {
        fwrite(STDERR, "ERROR: failed to create workdir {$workdir}\n");
        exit(1);
    }

    try {
        composerCreateProject($version, $workdir);
        $lockPath = $workdir . '/composer.lock';
        if (!is_file($lockPath)) {
            fwrite(STDERR, "ERROR: composer.lock not produced at {$lockPath}\n");
            exit(1);
        }
        $entries = parseLockShasums($lockPath);
        writeManifest($outputPath, $version, $entries);
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
Usage: php bin/build-composer-manifest.php --version=<tag> [--output=<path>]

  --version=<tag>   Magento Open Source version (e.g. 2.4.7-p5). Required.
  --output=<path>   Override the default output path
                    (etc/manifests/composer-sha1-community-<version>.json).
  -h, --help        Show this message.

Requires `composer` on PATH. Network access is required at build time.
Adobe Commerce coverage is intentionally out of scope; coverage runs
server-side in the v3 hosted backend where paid composer auth lives.

USAGE);
}

function composerCreateProject(string $version, string $workdir): void
{
    // Defence-in-depth — tag comes from CLI, never from the runtime check.
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $version)) {
        fwrite(STDERR, "ERROR: refusing to create-project with unexpected version: {$version}\n");
        exit(1);
    }
    // `--no-install` keeps this fast and dependency-light — we only need
    // composer.lock. `--no-interaction` is belt-and-braces for CI; the
    // script is not interactive on its own.
    $cmd = sprintf(
        'composer create-project --no-interaction --no-install --no-progress --no-secure-http=false %s %s %s',
        escapeshellarg(PROJECT_PACKAGE),
        escapeshellarg($workdir),
        escapeshellarg($version)
    );
    fwrite(STDOUT, "  $cmd\n");
    passthru($cmd, $exit);
    if ($exit !== 0) {
        fwrite(STDERR, "ERROR: composer create-project exited with status {$exit}\n");
        exit(1);
    }
}

/**
 * Walk composer.lock and emit `<vendor>/<package> => dist.shasum`.
 * Packages without a `dist.shasum` are omitted.
 *
 * Both `packages` and `packages-dev` are merged — a tampered dev tool
 * is still tampered code on disk at runtime.
 *
 * @return array<string,string>
 */
function parseLockShasums(string $lockPath): array
{
    $raw = file_get_contents($lockPath);
    if ($raw === false) {
        fwrite(STDERR, "ERROR: failed to read {$lockPath}\n");
        exit(1);
    }
    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fwrite(STDERR, "ERROR: invalid JSON in {$lockPath}: " . $e->getMessage() . "\n");
        exit(1);
    }
    if (!is_array($decoded)) {
        fwrite(STDERR, "ERROR: unexpected composer.lock shape\n");
        exit(1);
    }

    $entries = [];
    foreach (['packages', 'packages-dev'] as $bucket) {
        $list = $decoded[$bucket] ?? [];
        if (!is_array($list)) {
            continue;
        }
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = $entry['name'] ?? null;
            $dist = $entry['dist'] ?? null;
            if (!is_string($name) || $name === '' || !is_array($dist)) {
                continue;
            }
            $shasum = $dist['shasum'] ?? null;
            if (!is_string($shasum) || $shasum === '') {
                continue;
            }
            $entries[$name] = strtolower($shasum);
        }
    }

    ksort($entries);
    return $entries;
}

/**
 * @param array<string,string> $entries
 */
function writeManifest(string $outputPath, string $version, array $entries): void
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
        'source' => 'composer create-project ' . PROJECT_PACKAGE,
        'source_ref' => $version,
        'generated_at' => gmdate('Y-m-d'),
        'algorithm' => ALGORITHM,
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
