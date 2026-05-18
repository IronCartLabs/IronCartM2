<?php

/**
 * Build the Adobe Commerce Marketplace edition of IronCart_Scan.
 *
 * Mirrors the OSS `ironcartlabs/magento-scan` source one-for-one into a
 * staged tree under `package-marketplace/build/staging/`, swaps in the
 * Marketplace-shaped composer.json, and produces a tarball ready for
 * submission to the Marketplace.
 *
 * Version-skew mitigation
 * -----------------------
 * There are three places the module version lives, and they must all
 * agree before a Marketplace tarball is built:
 *
 *   1. `etc/module.xml` `setup_version`          (canonical — read by Magento at runtime)
 *   2. `composer.json` `extra.module-version`    (OSS Packagist package metadata)
 *   3. `package-marketplace/composer.json`
 *        `extra.module-version`                  (Marketplace package metadata)
 *
 * This script reads (1) as the source of truth and fails loudly if
 * (2) or (3) is not in lockstep. The dual-release CI workflow
 * (`.github/workflows/release-marketplace.yml`) also asserts that all
 * three agree with the git tag being released.
 *
 * Sync model
 * ----------
 * The Marketplace tarball contains the **same module code** as the OSS
 * package. The only thing that differs is the composer.json metadata.
 * That keeps OSS and Marketplace users on the same code path and avoids
 * a class of "the Marketplace fork drifted" bugs.
 *
 * Usage
 * -----
 *   php bin/build-marketplace.php [--check-only]
 *
 *   --check-only   Run the version-skew check but do not stage or tar.
 *                  Used by the CI workflow as a fast pre-flight.
 *
 * Exit codes
 * ----------
 *   0  success
 *   1  version skew between module.xml, root composer.json, and
 *      package-marketplace/composer.json
 *   2  missing required source path (e.g. no etc/module.xml)
 *   3  staging / archive failure
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Argument parsing.
// ---------------------------------------------------------------------------

$checkOnly = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--check-only') {
        $checkOnly = true;
        continue;
    }
    fwrite(STDERR, "ERROR: unknown argument: {$arg}\n");
    fwrite(STDERR, "Usage: php bin/build-marketplace.php [--check-only]\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Repo paths.
// ---------------------------------------------------------------------------

$repoRoot   = dirname(__DIR__);
$moduleXml  = $repoRoot . '/etc/module.xml';
$ossJson    = $repoRoot . '/composer.json';
$mpJson     = $repoRoot . '/package-marketplace/composer.json';
$stagingDir = $repoRoot . '/package-marketplace/build/staging';
$buildDir   = $repoRoot . '/package-marketplace/build';

foreach ([$moduleXml, $ossJson, $mpJson] as $required) {
    if (!is_file($required)) {
        fwrite(STDERR, "ERROR: missing required file: {$required}\n");
        exit(2);
    }
}

// ---------------------------------------------------------------------------
// Read canonical version from etc/module.xml.
// ---------------------------------------------------------------------------

$xml = @simplexml_load_file($moduleXml);
if ($xml === false) {
    fwrite(STDERR, "ERROR: could not parse {$moduleXml}\n");
    exit(2);
}
$canonicalVersion = (string)($xml->module['setup_version'] ?? '');
if ($canonicalVersion === '') {
    fwrite(STDERR, "ERROR: etc/module.xml has no <module setup_version=\"...\"> attribute\n");
    exit(2);
}
fwrite(STDOUT, ">>> canonical module version (etc/module.xml setup_version): {$canonicalVersion}\n");

// ---------------------------------------------------------------------------
// Version-skew check.
// ---------------------------------------------------------------------------

$ossManifest = json_decode((string)file_get_contents($ossJson), true);
$mpManifest  = json_decode((string)file_get_contents($mpJson), true);

if (!is_array($ossManifest) || !is_array($mpManifest)) {
    fwrite(STDERR, "ERROR: could not parse OSS or marketplace composer.json\n");
    exit(2);
}

$ossVersion = $ossManifest['extra']['module-version'] ?? null;
$mpVersion  = $mpManifest['extra']['module-version'] ?? null;

$mismatches = [];
if ($ossVersion !== $canonicalVersion) {
    $mismatches[] = "  composer.json extra.module-version = " . var_export($ossVersion, true)
        . " (expected {$canonicalVersion})";
}
if ($mpVersion !== $canonicalVersion) {
    $mismatches[] = "  package-marketplace/composer.json extra.module-version = "
        . var_export($mpVersion, true) . " (expected {$canonicalVersion})";
}

if ($mismatches !== []) {
    fwrite(STDERR, "ERROR: version skew between module.xml and one or more composer.json files:\n");
    foreach ($mismatches as $line) {
        fwrite(STDERR, $line . "\n");
    }
    fwrite(STDERR, "Fix: bump the lagging file(s) to {$canonicalVersion} and re-run.\n");
    exit(1);
}

fwrite(STDOUT, ">>> version-skew check OK — all three sources agree on {$canonicalVersion}\n");

// Optional gate: an env-provided release tag (set by the CI workflow on tag
// push) must equal the canonical version. Skipped on local invocations.
$releaseTag = getenv('IRONCART_RELEASE_TAG') ?: '';
if ($releaseTag !== '') {
    $tagVersion = ltrim($releaseTag, 'v');
    if ($tagVersion !== $canonicalVersion) {
        fwrite(STDERR, "ERROR: IRONCART_RELEASE_TAG={$releaseTag} (= {$tagVersion}) does not match "
            . "canonical version {$canonicalVersion}\n");
        exit(1);
    }
    fwrite(STDOUT, ">>> release tag {$releaseTag} matches canonical version\n");
}

if ($checkOnly) {
    fwrite(STDOUT, ">>> --check-only: skipping staging and archive\n");
    exit(0);
}

// ---------------------------------------------------------------------------
// Stage OSS source -> package-marketplace/build/staging/.
// ---------------------------------------------------------------------------

// Paths copied verbatim from the OSS module root. Keep in sync with the
// `archive.exclude` list in package-marketplace/composer.json (which
// describes the inverse — what NOT to ship). Anything not listed here is
// either repo plumbing (CI, sandbox, contributor docs) or already covered
// by the OSS Packagist artifact's own exclusions.
$includeDirs = [
    'Check',
    'Console',
    'Controller',
    'Cron',
    'Model',
    'Report',
    'Ui',
    'data',
    'etc',
    'view',
];
$includeFiles = [
    'registration.php',
    'README.md',
    'LICENSE',
    'SECURITY.md',
];

// Wipe and recreate staging.
if (is_dir($stagingDir)) {
    rrmdir($stagingDir);
}
if (!mkdir($stagingDir, 0o755, true) && !is_dir($stagingDir)) {
    fwrite(STDERR, "ERROR: could not create staging dir {$stagingDir}\n");
    exit(3);
}

foreach ($includeDirs as $dir) {
    $src = $repoRoot . '/' . $dir;
    if (!is_dir($src)) {
        fwrite(STDERR, "ERROR: expected source dir not found: {$src}\n");
        exit(2);
    }
    fwrite(STDOUT, ">>> staging dir: {$dir}\n");
    rcopy($src, $stagingDir . '/' . $dir);
}
foreach ($includeFiles as $file) {
    $src = $repoRoot . '/' . $file;
    if (!is_file($src)) {
        fwrite(STDERR, "ERROR: expected source file not found: {$src}\n");
        exit(2);
    }
    fwrite(STDOUT, ">>> staging file: {$file}\n");
    if (!copy($src, $stagingDir . '/' . $file)) {
        fwrite(STDERR, "ERROR: copy failed for {$src}\n");
        exit(3);
    }
}

// Drop in the Marketplace-shaped composer.json (not the OSS one).
fwrite(STDOUT, ">>> staging file: composer.json (marketplace edition)\n");
if (!copy($mpJson, $stagingDir . '/composer.json')) {
    fwrite(STDERR, "ERROR: copy failed for marketplace composer.json\n");
    exit(3);
}

// ---------------------------------------------------------------------------
// composer validate against the staged tree.
//
// `--no-check-publish` because the Marketplace package is intentionally
// not published to Packagist — strict-publish would complain about that.
// ---------------------------------------------------------------------------

$composer = trim((string)shell_exec('command -v composer 2>/dev/null'));
if ($composer === '') {
    fwrite(STDERR, "WARNING: composer not on PATH — skipping composer validate. "
        . "The CI workflow will run validate independently.\n");
} else {
    fwrite(STDOUT, ">>> running composer validate against staged tree\n");
    $cmd = sprintf(
        '%s validate --strict --no-check-publish --working-dir=%s 2>&1',
        escapeshellcmd($composer),
        escapeshellarg($stagingDir)
    );
    $out  = [];
    $code = 0;
    exec($cmd, $out, $code);
    foreach ($out as $line) {
        fwrite(STDOUT, "  {$line}\n");
    }
    if ($code !== 0) {
        fwrite(STDERR, "ERROR: composer validate failed on staged tree (exit {$code})\n");
        exit(3);
    }
}

// ---------------------------------------------------------------------------
// Tarball.
// ---------------------------------------------------------------------------

$tarName = sprintf('ironcartlabs-magento-scan-marketplace-%s.tar.gz', $canonicalVersion);
$tarPath = $buildDir . '/' . $tarName;
if (is_file($tarPath)) {
    unlink($tarPath);
}

// Use tar (GNU) for the archive. We deliberately tar the staged tree as
// `./` rather than `./staging/` so the tarball extracts to a clean
// composer-package layout (no enclosing directory in the way of
// `composer require` from a path).
$tar = trim((string)shell_exec('command -v tar 2>/dev/null'));
if ($tar === '') {
    fwrite(STDERR, "ERROR: tar not on PATH; cannot build archive.\n");
    exit(3);
}
$cmd = sprintf(
    '%s -czf %s -C %s .',
    escapeshellcmd($tar),
    escapeshellarg($tarPath),
    escapeshellarg($stagingDir)
);
fwrite(STDOUT, ">>> creating archive: {$tarPath}\n");
$out  = [];
$code = 0;
exec($cmd, $out, $code);
if ($code !== 0) {
    foreach ($out as $line) {
        fwrite(STDERR, "  {$line}\n");
    }
    fwrite(STDERR, "ERROR: tar exited {$code}\n");
    exit(3);
}

clearstatcache(true, $tarPath);
$bytes = (int)filesize($tarPath);
fwrite(STDOUT, sprintf(">>> done. %s (%d bytes)\n", $tarPath, $bytes));
exit(0);

// ---------------------------------------------------------------------------
// Helpers.
// ---------------------------------------------------------------------------

/**
 * Recursive copy. Skips symlinks (Magento sandbox symlinks point at the
 * working tree and would create cycles in the staged copy).
 */
function rcopy(string $src, string $dst): void
{
    if (!mkdir($dst, 0o755, true) && !is_dir($dst)) {
        fwrite(STDERR, "ERROR: could not create {$dst}\n");
        exit(3);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $sub = substr((string)$item->getPathname(), strlen($src) + 1);
        $target = $dst . '/' . $sub;
        if ($item->isLink()) {
            // Skip symlinks; the sandbox symlinks `_m2-workspace` into the
            // module tree which is not part of the shipped package.
            continue;
        }
        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0o755, true) && !is_dir($target)) {
                fwrite(STDERR, "ERROR: could not create {$target}\n");
                exit(3);
            }
        } else {
            if (!copy((string)$item->getPathname(), $target)) {
                fwrite(STDERR, "ERROR: copy failed for {$item->getPathname()}\n");
                exit(3);
            }
        }
    }
}

/** Recursive rmdir. */
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir((string)$item->getPathname());
        } else {
            unlink((string)$item->getPathname());
        }
    }
    rmdir($dir);
}
