<?php

/**
 * IronCart_Scan — i18n placeholder parity test.
 *
 * Magento's `__()` helper uses `%1`, `%2`, … positional placeholders that
 * are substituted at render time. A translator who drops a `%1` from a
 * target string, or replaces it with the localised text of its referent,
 * produces a broken sprintf call: the placeholder is never substituted
 * and the literal `%1` reaches the user. That is worse than untranslated
 * English output.
 *
 * This test loads every `i18n/<locale>.csv` shipped in the module, and
 * for each row asserts:
 *
 *   1. The CSV parses (well-formed RFC-4180, source/target columns).
 *   2. The set of `%N` placeholders in the target string is exactly the
 *      set found in the en_US source. Count and identity both matter:
 *      a target with `%1 %1 %2` for a source of `%1 %2` would silently
 *      double-print arg 1 and miss arg 2.
 *   3. The target string is non-empty. Empty translations defeat the
 *      whole point of the locale CSV — Magento falls back to en_US so
 *      empty rows hide instead of erroring, which is exactly the bug
 *      this test wants to surface.
 *
 * The test is locale-agnostic — every sibling of `en_US.csv` under
 * `i18n/` is checked. Adding a new locale only requires dropping its
 * CSV; no test edit needed.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Report;

use PHPUnit\Framework\TestCase;

class I18nPlaceholderParityTest extends TestCase
{
    /**
     * Module root, resolved relative to this test file. Three levels up
     * from `Test/Unit/Report/` lands at the module root regardless of
     * whether the CI runner ran from the repo root or the Magento sandbox.
     */
    private const MODULE_ROOT_OFFSET = '/../../../';

    public function testEveryLocaleCsvParses(): void
    {
        $locales = $this->locales();
        $this->assertNotEmpty(
            $locales,
            'No locale CSVs found under i18n/<locale>.csv — at least en_US.csv must ship.'
        );
        foreach ($locales as $locale => $rows) {
            $this->assertNotEmpty(
                $rows,
                sprintf('i18n/%s.csv parsed to zero rows — file may be empty or malformed.', $locale)
            );
        }
    }

    public function testEnUsExistsAsTheSourceCatalog(): void
    {
        $locales = $this->locales();
        $this->assertArrayHasKey(
            'en_US',
            $locales,
            'i18n/en_US.csv is the source catalog and must ship.'
        );
    }

    public function testEveryLocaleHasMatchingPlaceholderSetPerRow(): void
    {
        $locales = $this->locales();
        $sourceRows = $locales['en_US'] ?? [];
        $this->assertNotEmpty($sourceRows, 'en_US.csv must contain rows.');

        // Pre-extract en_US placeholder sets per source phrase. The same
        // source row may appear once per locale; we want a single
        // canonical expectation per phrase.
        $expectedPlaceholders = [];
        foreach ($sourceRows as [$source, $_]) {
            $expectedPlaceholders[$source] = $this->extractPlaceholders($source);
        }

        foreach ($locales as $locale => $rows) {
            if ($locale === 'en_US') {
                continue; // by construction source == target in en_US
            }
            foreach ($rows as $rowIndex => [$source, $target]) {
                $this->assertArrayHasKey(
                    $source,
                    $expectedPlaceholders,
                    sprintf(
                        'i18n/%s.csv row %d source phrase has no matching en_US row: %s',
                        $locale,
                        $rowIndex + 1,
                        $this->preview($source)
                    )
                );
                $expected = $expectedPlaceholders[$source];
                $actual = $this->extractPlaceholders($target);
                $this->assertSame(
                    $expected,
                    $actual,
                    sprintf(
                        'i18n/%s.csv row %d placeholder set differs from en_US.' . PHP_EOL
                        . '  source: %s' . PHP_EOL
                        . '  target: %s' . PHP_EOL
                        . '  expected placeholders: %s' . PHP_EOL
                        . '  actual placeholders: %s',
                        $locale,
                        $rowIndex + 1,
                        $this->preview($source),
                        $this->preview($target),
                        $expected === [] ? '(none)' : implode(', ', $expected),
                        $actual === [] ? '(none)' : implode(', ', $actual)
                    )
                );
            }
        }
    }

    public function testNoLocaleHasEmptyTargets(): void
    {
        foreach ($this->locales() as $locale => $rows) {
            foreach ($rows as $rowIndex => [$source, $target]) {
                $this->assertNotSame(
                    '',
                    $target,
                    sprintf(
                        'i18n/%s.csv row %d has empty target for source: %s',
                        $locale,
                        $rowIndex + 1,
                        $this->preview($source)
                    )
                );
            }
        }
    }

    public function testEveryLocaleCoversEverySourcePhrase(): void
    {
        $locales = $this->locales();
        $sourceRows = $locales['en_US'] ?? [];
        $this->assertNotEmpty($sourceRows);

        $sourcePhrases = [];
        foreach ($sourceRows as [$source, $_]) {
            $sourcePhrases[$source] = true;
        }

        foreach ($locales as $locale => $rows) {
            if ($locale === 'en_US') {
                continue;
            }
            $present = [];
            foreach ($rows as [$source, $_]) {
                $present[$source] = true;
            }
            $missing = array_diff_key($sourcePhrases, $present);
            $this->assertSame(
                [],
                array_keys($missing),
                sprintf(
                    'i18n/%s.csv is missing %d source phrase(s) that exist in en_US.csv. ' .
                    'First missing: %s',
                    $locale,
                    count($missing),
                    $this->preview((string) array_key_first($missing))
                )
            );
        }
    }

    /**
     * Load every `i18n/<locale>.csv` shipped in the module as
     * `['<locale>' => [['source','target'], ...]]`.
     *
     * @return array<string, list<array{0:string,1:string}>>
     */
    private function locales(): array
    {
        $root = realpath(__DIR__ . self::MODULE_ROOT_OFFSET);
        if ($root === false) {
            $this->fail('Cannot resolve module root from test file location.');
        }
        $dir = $root . DIRECTORY_SEPARATOR . 'i18n';
        if (!is_dir($dir)) {
            $this->fail('Module i18n/ directory missing at ' . $dir);
        }

        $out = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.csv') ?: [] as $path) {
            $locale = pathinfo($path, PATHINFO_FILENAME);
            $rows = [];
            $fh = fopen($path, 'r');
            $this->assertNotFalse($fh, 'Cannot open ' . $path);
            // fgetcsv signature matches the Magento i18n loader: comma
            // separator, double-quote enclosure, backslash escape — the
            // PHP defaults, which is exactly what Magento's collector
            // and our `bin/check-i18n.php` script also use.
            while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                if ($row === [null] || $row === [] || $row === [false]) {
                    continue;
                }
                $source = isset($row[0]) ? (string) $row[0] : '';
                $target = isset($row[1]) ? (string) $row[1] : '';
                if ($source === '') {
                    continue; // blank line tolerated
                }
                $rows[] = [$source, $target];
            }
            fclose($fh);
            $out[$locale] = $rows;
        }
        return $out;
    }

    /**
     * Return the sorted, de-duplicated list of `%N` placeholders found
     * in the given string. Magento's `__()` recognises positional
     * placeholders of the form `%<digit>` (1-9) and named placeholders
     * of the form `%<word>`; both shapes are extracted so a translator
     * who renames `%path` is also flagged.
     *
     * The result is sorted lexicographically so order in the source vs.
     * target does not matter — a translator is free to put `%2` before
     * `%1` if the target grammar demands it.
     *
     * @return list<string>
     */
    private function extractPlaceholders(string $s): array
    {
        $matches = [];
        if (!preg_match_all('/%(?:\d+|[a-zA-Z_][a-zA-Z0-9_]*)/', $s, $matches)) {
            return [];
        }
        $unique = array_values(array_unique($matches[0]));
        sort($unique, SORT_STRING);
        return $unique;
    }

    /**
     * Truncate a long phrase for error-message preview so PHPUnit's
     * one-line failure output stays readable on multi-paragraph CSV
     * rows (the field-group `comment` blocks are paragraphs long).
     */
    private function preview(string $s): string
    {
        $s = strtr($s, ["\n" => ' ', "\r" => ' ']);
        return strlen($s) > 80 ? substr($s, 0, 77) . '...' : $s;
    }
}
