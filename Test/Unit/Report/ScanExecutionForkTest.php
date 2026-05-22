<?php

/**
 * IronCart_Scan — scan-execution fork pin test (#150).
 *
 * `Model\ScanEngineRunner::runAndReport()` is the shared scan engine
 * for THREE intentionally-forked execution sites — see
 * `docs/scan-execution-lifecycle.md` for the full decision record and
 * per-site state diagrams. This test pins those three sites by
 * filesystem-grep over the module tree: any new file that calls
 * `$this->scanEngineRunner->runAndReport()` (on any reference name)
 * fails the build unless the contributor adds the new call site to
 * {@see self::EXPECTED_CALL_SITES}.
 *
 * Why filesystem-grep and not reflection: the CI unit cell does not
 * load `magento/framework`, so importing the concrete callers (which
 * extend Magento base classes) would fail at autoload. Static analysis
 * over the source text is the same approach the existing `Report/*`
 * shape tests use (see `AdminRouteAliasShapeTest`, `DbSchemaShapeTest`,
 * etc.) and matches the comment-block-as-source-of-truth pattern from
 * `ScanRunTerminalState`.
 *
 * Why this test lives under `Test/Unit/Report/` (the Magento-free unit
 * slice) and not next to the callers: the CI unit job restricts the
 * testsuite to `Test/Unit/Report/**` precisely because that subtree
 * runs without `magento/framework` on the classpath
 * (see .github/workflows/ci.yml). The fork-pin invariant has no
 * Magento dependency — it is a pure-PHP grep over file contents — so
 * it slots cleanly into the Report subtree alongside the other shape
 * tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Report;

use PHPUnit\Framework\TestCase;

/**
 * Pins the three intentional callers of `ScanEngineRunner::runAndReport()`.
 *
 * @covers \IronCart\Scan\Model\ScanEngineRunner
 */
class ScanExecutionForkTest extends TestCase
{
    /**
     * The canonical call sites for `scanEngineRunner->runAndReport()`
     * across the module. Each entry maps a module-root-relative file
     * path to the method name we expect the call to live inside. The
     * lifecycle contract column is documentation only — the assertion
     * is over the file/method pair.
     *
     * `Model/ScanEngineRunner.php` itself is excluded from the grep
     * (see {@see self::SCAN_ENGINE_RUNNER_REL_PATH}) because that file
     * defines `runAndReport()` and contains the one `checkRegistry->runAll()`
     * call — it is the orchestrator, not a caller.
     *
     * Lifecycle contracts (see docs/scan-execution-lifecycle.md):
     *
     *   - Console/Command/ScanCommand.php::execute
     *       CLI. No `ironcart_scan_run` row. Optional `--upload`.
     *   - Cron/UploadScan.php::execute
     *       Cron. No `ironcart_scan_run` row. Always uploads.
     *   - Model/ScanRunConsumer.php::runScan
     *       DB-queue consumer (admin "Run Scan Now"). Writes a
     *       `ironcart_scan_run` row. Never uploads. The MQ entry
     *       point is `::process(string $body)`, which deserialises
     *       the topic payload, acquires the drain lock (#155), and
     *       delegates to the private `runScan(ScanRun $run)` where
     *       `scanEngineRunner->runAndReport()` is actually invoked.
     *       The pin is on `runScan` because that is where the
     *       lifecycle contract lives — moving the engine call out of
     *       `runScan` into a sibling method would be a real
     *       lifecycle change.
     *
     * @var array<string,string>
     */
    private const EXPECTED_CALL_SITES = [
        'Console/Command/ScanCommand.php' => 'execute',
        'Cron/UploadScan.php'             => 'execute',
        'Model/ScanRunConsumer.php'       => 'runScan',
    ];

    /**
     * The orchestrator itself — excluded from the caller grep because
     * it defines `runAndReport()` and is the single point that invokes
     * `checkRegistry->runAll()`. Counting it as a "caller" would be
     * self-referential.
     */
    private const SCAN_ENGINE_RUNNER_REL_PATH = 'Model/ScanEngineRunner.php';

    /**
     * Module-root-relative subtrees the grep walks. Tests/* are excluded
     * intentionally: a test file that constructs a fake ScanEngineRunner
     * and calls `runAndReport()` on it is NOT a new lifecycle, and we
     * don't want it to trip the pin.
     *
     * `vendor/`, `_m2-workspace/`, and other build artefacts are
     * excluded by the recursive iterator's filter (see {@see self::iterPhpFiles()}).
     *
     * @var list<string>
     */
    private const SCAN_ROOTS = [
        'Check',
        'Console',
        'Controller',
        'Cron',
        'Model',
        'Report',
        'Setup',
        'Ui',
    ];

    public function testEveryCallSiteIsKnown(): void
    {
        $moduleRoot = $this->resolveModuleRoot();
        $foundCallSites = $this->scanForCallSites($moduleRoot);

        $unexpected = array_diff_key($foundCallSites, self::EXPECTED_CALL_SITES);

        self::assertSame(
            [],
            array_keys($unexpected),
            sprintf(
                "Unexpected caller(s) of ScanEngineRunner::runAndReport() detected: %s.\n"
                . 'A new scan-execution lifecycle has appeared. Update '
                . '`docs/scan-execution-lifecycle.md` (per-caller state '
                . 'diagram + "when to add a fourth caller" checklist) AND '
                . 'add the file to ScanExecutionForkTest::EXPECTED_CALL_SITES '
                . 'before this PR can merge. See #150 for the rationale.',
                implode(', ', array_keys($unexpected))
            )
        );
    }

    public function testEveryExpectedCallSiteStillExists(): void
    {
        $moduleRoot = $this->resolveModuleRoot();
        $foundCallSites = $this->scanForCallSites($moduleRoot);

        $missing = array_diff_key(self::EXPECTED_CALL_SITES, $foundCallSites);

        self::assertSame(
            [],
            array_keys($missing),
            sprintf(
                "Expected caller(s) of ScanEngineRunner::runAndReport() are missing from "
                . "the source tree: %s.\n"
                . 'A scan-execution lifecycle has been removed or renamed. '
                . 'Update `docs/scan-execution-lifecycle.md` and '
                . 'ScanExecutionForkTest::EXPECTED_CALL_SITES to match. '
                . 'If this is a deletion, also confirm the admin-grid / '
                . 'upload / cron surface still has at least one path. See #150.',
                implode(', ', array_keys($missing))
            )
        );
    }

    public function testEachCallSiteLivesInItsExpectedMethod(): void
    {
        $moduleRoot = $this->resolveModuleRoot();

        foreach (self::EXPECTED_CALL_SITES as $relPath => $expectedMethod) {
            $absPath = $moduleRoot . DIRECTORY_SEPARATOR . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $relPath
            );

            self::assertFileExists(
                $absPath,
                sprintf(
                    'Expected scan-execution call site %s is missing — was it '
                    . 'renamed? Update EXPECTED_CALL_SITES and the lifecycle docs.',
                    $relPath
                )
            );

            $methods = $this->methodsContainingScanEngineRunnerCall($absPath);

            self::assertContains(
                $expectedMethod,
                $methods,
                sprintf(
                    'ScanEngineRunner::runAndReport() was expected inside %s::%s() but '
                    . 'was found in [%s] instead. The lifecycle contract is '
                    . 'documented against the method, not the file — moving '
                    . 'the call between methods inside the same file is a '
                    . 'lifecycle change. See docs/scan-execution-lifecycle.md.',
                    $relPath,
                    $expectedMethod,
                    implode(', ', $methods) ?: '<none>'
                )
            );
        }
    }

    /**
     * Resolve the module root regardless of where PHPUnit is invoked
     * from. `__DIR__` is `Test/Unit/Report/`, so the module root is
     * three levels up.
     */
    private function resolveModuleRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Walk the scan roots and return every relative file path that
     * contains a `scanEngineRunner->runAndReport()` invocation. The
     * orchestrator file itself ({@see self::SCAN_ENGINE_RUNNER_REL_PATH})
     * is excluded.
     *
     * @return array<string,bool> keyed by module-root-relative path
     */
    private function scanForCallSites(string $moduleRoot): array
    {
        $hits = [];
        foreach (self::SCAN_ROOTS as $root) {
            $absRoot = $moduleRoot . DIRECTORY_SEPARATOR . $root;
            if (!is_dir($absRoot)) {
                continue;
            }
            foreach ($this->iterPhpFiles($absRoot) as $absPath) {
                $relPath = ltrim(
                    str_replace(
                        DIRECTORY_SEPARATOR,
                        '/',
                        substr($absPath, strlen($moduleRoot))
                    ),
                    '/'
                );
                if ($relPath === self::SCAN_ENGINE_RUNNER_REL_PATH) {
                    // The orchestrator is not a caller of itself.
                    continue;
                }
                $contents = (string) file_get_contents($absPath);
                if ($this->fileInvokesScanEngineRunner($contents)) {
                    $hits[$relPath] = true;
                }
            }
        }
        return $hits;
    }

    /**
     * Detect a `->scanEngineRunner->runAndReport(` invocation in source
     * text.
     *
     * Matches against the canonical property-name `scanEngineRunner`
     * used by all three current callers. Comments containing the
     * literal text are filtered out by stripping `//`-to-EOL and
     * `/* ... *\/` blocks before matching — this is what stops the
     * doc-block on `ScanEngineRunner::runAndReport()` (which lists
     * the three callers as `::execute()` / `::runScan()`) from
     * registering as a self-call, on top of the path-based exclusion
     * in {@see self::scanForCallSites()}.
     */
    private function fileInvokesScanEngineRunner(string $contents): bool
    {
        $stripped = $this->stripPhpComments($contents);
        // Property access shape used in all three current callers.
        // The leading `->` is required so `ScanEngineRunner::runAndReport()`
        // (the method definition) does not match itself.
        return (bool) preg_match(
            '/->\s*scanEngineRunner\s*->\s*runAndReport\s*\(/i',
            $stripped
        );
    }

    /**
     * Return the names of methods inside `$absPath` that contain a
     * `scanEngineRunner->runAndReport()` call. Used by the assertion
     * that pins the call to its expected method.
     *
     * @return list<string>
     */
    private function methodsContainingScanEngineRunnerCall(string $absPath): array
    {
        $contents = $this->stripPhpComments((string) file_get_contents($absPath));

        // Find every `function name(...)` declaration and the byte offset
        // of its opening brace. We then walk from each brace to its
        // matching `}` and check whether the body contains the call.
        $methods = [];
        $offset = 0;
        $len = strlen($contents);
        while (preg_match(
            '/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
            $contents,
            $m,
            PREG_OFFSET_CAPTURE,
            $offset
        )) {
            $name = $m[1][0];
            $afterParen = strpos($contents, ')', (int) $m[0][1]);
            if ($afterParen === false) {
                break;
            }
            $braceOpen = strpos($contents, '{', $afterParen);
            if ($braceOpen === false) {
                // Abstract / interface method — no body.
                $offset = $afterParen + 1;
                continue;
            }
            $braceClose = $this->matchClosingBrace($contents, $braceOpen);
            if ($braceClose === null) {
                break;
            }
            $body = substr($contents, $braceOpen, $braceClose - $braceOpen + 1);
            if (preg_match('/->\s*scanEngineRunner\s*->\s*runAndReport\s*\(/i', $body)) {
                $methods[] = $name;
            }
            $offset = $braceClose + 1;
            if ($offset >= $len) {
                break;
            }
        }
        return $methods;
    }

    /**
     * Locate the matching `}` for the `{` at `$openIdx`, accounting
     * for nested braces. PHP string interpolation can embed `{` inside
     * string literals, so this is approximate — but the callers we
     * care about have small method bodies with no string-embedded
     * braces, and the test would still surface a real lifecycle
     * change as an "unexpected caller" before it'd produce a wrong
     * method match.
     *
     * @return int|null  Index of the matching `}`, or null if unbalanced.
     */
    private function matchClosingBrace(string $contents, int $openIdx): ?int
    {
        $depth = 0;
        $len = strlen($contents);
        for ($i = $openIdx; $i < $len; $i++) {
            $c = $contents[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * Strip `//` line-comments and `/* ... *\/` block-comments from
     * PHP source so the regex grep doesn't trip on doc-blocks (e.g.
     * the comment on `ScanEngineRunner::runAndReport()` itself, which
     * lists the three callers by name as `::execute()` / `::runScan()`).
     *
     * Heredoc / nowdoc bodies are NOT stripped — we don't care about
     * them for this grep because no caller embeds the property-access
     * pattern inside a heredoc.
     */
    private function stripPhpComments(string $contents): string
    {
        // Block comments first.
        $contents = (string) preg_replace('!/\*.*?\*/!s', '', $contents);
        // Then `//` and `#` line comments.
        $contents = (string) preg_replace('/(?:\/\/|#)[^\n]*/', '', $contents);
        return $contents;
    }

    /**
     * Recursively yield every `.php` file under `$root`, skipping
     * vendor / worktree / build-artefact directories.
     *
     * @return iterable<string>
     */
    private function iterPhpFiles(string $root): iterable
    {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator(
                    $root,
                    \FilesystemIterator::SKIP_DOTS
                ),
                static function (\SplFileInfo $file): bool {
                    $name = $file->getFilename();
                    // Skip noisy / vendor-shaped dirs even though we
                    // shouldn't be reaching them from the configured
                    // SCAN_ROOTS, just to keep the test cheap if the
                    // tree grows.
                    if ($file->isDir()) {
                        return !in_array($name, [
                            'vendor',
                            '_m2-workspace',
                            'node_modules',
                            '.git',
                        ], true);
                    }
                    return $file->isFile()
                        && str_ends_with($name, '.php');
                }
            )
        );
        foreach ($iter as $file) {
            /** @var \SplFileInfo $file */
            yield $file->getPathname();
        }
    }
}
