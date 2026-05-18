<?php

/**
 * IronCart_Scan — i18n CSV completeness checker.
 *
 * Adobe Marketplace EQP's `MEQP2.Translation.MissingI18n` rule is a hard
 * submission blocker: every translatable source string in the module must
 * have a row in `i18n/en_US.csv`. This script enumerates every:
 *
 *   - `__('...')` / `__("...")` call in *.php
 *   - `<label translate="true">…</label>` text in *.xml
 *   - `<comment translate="…">…</comment>` (or `<comment>` under a
 *     `translate="… comment …"` parent) text in *.xml
 *   - `title="…"` attribute on any element with `translate="title"` in *.xml
 *
 * …and compares against the source-string column of `i18n/en_US.csv`. Exits
 * non-zero (and prints a `::error::` line per missing row, so the GitHub
 * Actions log surfaces them inline) if any source phrase is absent.
 *
 * Usage:
 *
 *     php bin/check-i18n.php                   # checks i18n/en_US.csv
 *     php bin/check-i18n.php --csv=path.csv    # override CSV path
 *     php bin/check-i18n.php --print           # print every phrase found
 *
 * BUILD-TIME tool only. NOT invoked from the runtime scanner.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

main($argv);

function main(array $argv): void
{
    $opts = parseOptions(array_slice($argv, 1));
    if ($opts['help']) {
        printUsage();
        exit(0);
    }

    $root = dirname(__DIR__);
    $csvPath = $opts['csv'] !== '' ? $opts['csv'] : $root . '/i18n/en_US.csv';

    $phrases = collectPhrases($root);
    ksort($phrases, SORT_STRING);

    if ($opts['print']) {
        foreach ($phrases as $phrase => $sources) {
            echo $phrase . "\n";
            foreach ($sources as $src) {
                echo '    @ ' . $src . "\n";
            }
        }
        exit(0);
    }

    if (!is_file($csvPath)) {
        fwrite(STDERR, "::error::i18n CSV not found at {$csvPath}\n");
        fwrite(STDERR, "Run `php bin/check-i18n.php --print` to see every phrase the scanner expects.\n");
        exit(1);
    }

    $csvSources = loadCsvSources($csvPath);
    $missing = [];
    foreach ($phrases as $phrase => $sources) {
        if (!isset($csvSources[$phrase])) {
            $missing[$phrase] = $sources;
        }
    }

    if ($missing !== []) {
        fwrite(STDERR, "::error::i18n/en_US.csv is missing " . count($missing) . " source phrase(s):\n");
        foreach ($missing as $phrase => $sources) {
            $sample = $sources[0] ?? '(unknown)';
            $printable = strlen($phrase) > 120 ? substr($phrase, 0, 117) . '...' : $phrase;
            // Replace newlines so the GHA log shows one phrase per line.
            $printable = strtr($printable, ["\r" => ' ', "\n" => ' ']);
            fwrite(STDERR, "::error file={$sample}::Missing CSV row for: {$printable}\n");
        }
        fwrite(STDERR, "\nAdd a `\"<source>\",\"<source>\"` row to i18n/en_US.csv for each phrase above,\n");
        fwrite(STDERR, "then re-run `php bin/check-i18n.php`. See docs/i18n.md for the format.\n");
        exit(1);
    }

    // Catch the reverse case too: orphan CSV rows that no longer match any
    // source phrase. Not strictly an EQP blocker but keeps the file lean.
    $orphans = [];
    foreach ($csvSources as $source => $_) {
        if (!isset($phrases[$source])) {
            $orphans[] = $source;
        }
    }
    if ($orphans !== []) {
        fwrite(STDERR, "::warning::i18n/en_US.csv has " . count($orphans) . " orphan row(s) with no matching __() / translate= source:\n");
        foreach ($orphans as $orphan) {
            $printable = strlen($orphan) > 120 ? substr($orphan, 0, 117) . '...' : $orphan;
            $printable = strtr($printable, ["\r" => ' ', "\n" => ' ']);
            fwrite(STDERR, "::warning::  - {$printable}\n");
        }
        // Warning only — don't fail. EQP doesn't care about orphans.
    }

    $count = count($phrases);
    echo "i18n/en_US.csv OK — {$count} phrase(s) covered.\n";
    exit(0);
}

function parseOptions(array $argv): array
{
    $opts = ['csv' => '', 'print' => false, 'help' => false];
    foreach ($argv as $arg) {
        if ($arg === '-h' || $arg === '--help') {
            $opts['help'] = true;
        } elseif ($arg === '--print') {
            $opts['print'] = true;
        } elseif (str_starts_with($arg, '--csv=')) {
            $opts['csv'] = substr($arg, 6);
        }
    }
    return $opts;
}

function printUsage(): void
{
    echo <<<USAGE
Usage: php bin/check-i18n.php [--csv=path] [--print]

Verifies every __() and translate= source string in the module has a
matching row in i18n/en_US.csv.

Options:
  --csv=<path>   Path to en_US.csv (default: <repo>/i18n/en_US.csv).
  --print        Print every discovered phrase and exit 0 (no CSV check).
  -h, --help     Show this message.

USAGE;
}

/**
 * @return array<string, list<string>>  phrase => list of source files
 */
function collectPhrases(string $root): array
{
    $phrases = [];

    foreach (rglob($root, ['.git', 'vendor', '_m2-workspace', 'node_modules', '.sandbox', 'tests/_sandbox']) as $path) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'php' && !startsWith($path, $root . '/bin/')) {
            // bin/ scripts are build-time CLI utilities, never loaded by
            // Magento at runtime — their CLI-help strings are not part of
            // the user-facing UI surface MEQP cares about.
            foreach (extractPhpPhrases($path) as $p) {
                $phrases[$p][] = relpath($path, $root);
            }
        } elseif ($ext === 'xml') {
            foreach (extractXmlPhrases($path) as $p) {
                $phrases[$p][] = relpath($path, $root);
            }
        }
    }

    return $phrases;
}

/**
 * @return iterable<string>
 */
function rglob(string $dir, array $skipSegments): iterable
{
    $iter = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            static function ($file) use ($dir, $skipSegments) {
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
                foreach ($skipSegments as $seg) {
                    if ($rel === $seg || str_starts_with($rel, $seg . '/')) {
                        return false;
                    }
                }
                return true;
            }
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iter as $file) {
        if ($file->isFile()) {
            yield $file->getPathname();
        }
    }
}

/**
 * Extract `__('...')` / `__("...")` first-arg literals from a PHP file
 * using the PHP tokenizer (handles escapes, multi-line strings, and
 * nested call-sites correctly — regex would mis-grab method names like
 * `$obj->__construct(...)`).
 *
 * @return list<string>
 */
function extractPhpPhrases(string $path): array
{
    $src = file_get_contents($path);
    if ($src === false) {
        return [];
    }
    $tokens = token_get_all($src);
    $out = [];
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        // The translate helper is a plain function call: `__(...)`. The
        // tokenizer renders `__` as T_STRING. Guard against the method
        // form `$x->__(...)` (no real use in M2 but cheap to check).
        if (!is_array($t) || $t[0] !== T_STRING || $t[1] !== '__') {
            continue;
        }
        $prev = $i > 0 ? $tokens[$i - 1] : null;
        if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
            continue;
        }
        // Skip whitespace; expect `(`.
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $n || $tokens[$j] !== '(') {
            continue;
        }
        // Skip to first non-whitespace token after `(`.
        $j++;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $n || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) {
            // Not a plain string literal (e.g. `__($var)`) — skip. MEQP
            // also skips dynamic calls; only literal phrases need a CSV
            // row.
            continue;
        }
        $literal = $tokens[$j][1];
        $phrase = decodePhpStringLiteral($literal);
        if ($phrase !== null) {
            $out[] = $phrase;
        }
    }
    return $out;
}

/**
 * Decode a T_CONSTANT_ENCAPSED_STRING ('foo' or "foo") into its runtime
 * string value. Returns null on heredoc-style inputs we cannot decode.
 */
function decodePhpStringLiteral(string $lit): ?string
{
    if ($lit === '') {
        return null;
    }
    $first = $lit[0];
    if ($first === "'") {
        $inner = substr($lit, 1, -1);
        // Single-quoted: only \\ and \' are escapes.
        return strtr($inner, ["\\\\" => "\\", "\\'" => "'"]);
    }
    if ($first === '"') {
        $inner = substr($lit, 1, -1);
        // Double-quoted: parse via PHP's own evaluator. We tightly scope
        // the eval to a single string literal — no user input ever
        // reaches this path (always module source code).
        $eval = @eval('return "' . $inner . '";');
        return is_string($eval) ? $eval : null;
    }
    return null;
}

/**
 * Extract every translatable phrase from a Magento XML config file.
 *
 * Magento's `bin/magento i18n:collect-phrases` walks the DOM and pulls:
 *
 *   1. The `title` attribute of any element with `translate="title"`
 *      (or `translate="…title…"` for compound declarations).
 *   2. The trimmed text of any `<label>` whose enclosing context marks
 *      `label` as translatable — in practice every UI-component
 *      `<label translate="true">…</label>` and every system.xml
 *      `<label>` under a `translate="… label …"` parent.
 *   3. The trimmed text of any `<comment>` under a parent with
 *      `translate="… comment …"`.
 *
 * @return list<string>
 */
function extractXmlPhrases(string $path): array
{
    $src = file_get_contents($path);
    if ($src === false) {
        return [];
    }
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = true;
    $doc->formatOutput = false;
    // Suppress libxml warnings for the rare non-namespaced layout XML.
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($src);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok || $doc->documentElement === null) {
        return [];
    }

    $out = [];
    walkElement($doc->documentElement, $out);
    return $out;
}

/**
 * @param-out list<string> $out
 */
function walkElement(DOMElement $el, array &$out): void
{
    $translate = $el->hasAttribute('translate') ? (string)$el->getAttribute('translate') : '';
    $parts = $translate === '' ? [] : preg_split('/\s+/', trim($translate));

    if (in_array('title', $parts, true) && $el->hasAttribute('title')) {
        $title = (string)$el->getAttribute('title');
        $title = normalizePhrase($title);
        if ($title !== '') {
            $out[] = $title;
        }
    }

    // For `translate="label …"` we collect either the element's own text
    // (UI-component `<label translate="true">ID</label>` pattern) or the
    // direct child `<label>` element (system.xml pattern). Same shape for
    // `comment`.
    foreach (['label', 'comment'] as $kind) {
        if (!in_array($kind, $parts, true)) {
            continue;
        }
        // UI-component case: the element itself is `<label translate="true">`.
        if ($el->localName === $kind && $translate === 'true') {
            $text = collectTextContent($el);
            $text = normalizePhrase($text);
            if ($text !== '') {
                $out[] = $text;
            }
            continue;
        }
        // System.xml case: walk direct-child elements named `<label>` /
        // `<comment>` and pull their trimmed content.
        foreach (childElements($el, $kind) as $child) {
            $text = collectTextContent($child);
            $text = normalizePhrase($text);
            if ($text !== '') {
                $out[] = $text;
            }
        }
    }

    // Recurse into element children.
    foreach (iterator_to_array($el->childNodes) as $child) {
        if ($child instanceof DOMElement) {
            walkElement($child, $out);
        }
    }
}

/**
 * @return list<DOMElement>
 */
function childElements(DOMElement $el, string $name): array
{
    $out = [];
    foreach (iterator_to_array($el->childNodes) as $child) {
        if ($child instanceof DOMElement && $child->localName === $name) {
            $out[] = $child;
        }
    }
    return $out;
}

function collectTextContent(DOMElement $el): string
{
    // textContent concatenates child text + CDATA, matching what
    // Magento's i18n collector ultimately uses as the phrase key.
    return $el->textContent;
}

/**
 * Trim outer whitespace, collapse runs of internal whitespace to a single
 * space, and normalise CRLF → LF. Mirrors what Magento's i18n tooling
 * stores in `i18n/<locale>.csv` after collection — multi-line CDATA
 * comments survive as one logical phrase.
 */
function normalizePhrase(string $s): string
{
    $s = strtr($s, ["\r\n" => "\n", "\r" => "\n"]);
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return trim($s);
}

/**
 * @return array<string, true>
 */
function loadCsvSources(string $path): array
{
    $fh = fopen($path, 'r');
    if ($fh === false) {
        fwrite(STDERR, "::error::Cannot open {$path}\n");
        exit(1);
    }
    $sources = [];
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if ($row === [null] || $row === []) {
            continue;
        }
        $source = (string)($row[0] ?? '');
        if ($source === '') {
            continue;
        }
        $sources[normalizePhrase($source)] = true;
    }
    fclose($fh);
    return $sources;
}

function relpath(string $abs, string $root): string
{
    $abs = str_replace('\\', '/', $abs);
    $root = str_replace('\\', '/', $root);
    if (str_starts_with($abs, $root . '/')) {
        return substr($abs, strlen($root) + 1);
    }
    return $abs;
}

function startsWith(string $h, string $n): bool
{
    return str_starts_with(str_replace('\\', '/', $h), $n);
}
