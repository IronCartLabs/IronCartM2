<?php

/**
 * IronCart_Scan — run-listing severity indicator markup test.
 *
 * Pins the horizontal-row shape produced by
 * {@see \IronCart\Scan\Ui\Component\Listing\Column\Severity\SeverityIndicatorRow}.
 * Lives under Test/Unit/Report because that's the only testsuite the
 * v0 unit-CI cell loads (the cell strips magento/framework before
 * composer install, see .github/workflows/ci.yml). The helper itself
 * is Magento-free for exactly that reason.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Report;

use IronCart\Scan\Report\Severity;
use IronCart\Scan\Ui\Component\Listing\Column\Severity\SeverityIndicatorRow;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Ui\Component\Listing\Column\Severity\SeverityIndicatorRow
 */
class SeverityIndicatorRowTest extends TestCase
{
    public function testRendersAllFiveSeveritySlots(): void
    {
        $html = SeverityIndicatorRow::render([
            Severity::CRITICAL => 3,
            Severity::HIGH     => 1,
            Severity::MEDIUM   => 0,
            Severity::LOW      => 2,
            Severity::INFO     => 4,
        ]);

        // Five slots — one per severity. Counts must NOT be conditional;
        // empty severities still occupy a slot for alignment.
        self::assertSame(
            5,
            substr_count($html, 'ironcart-severity-slot'),
            'every severity must render exactly one slot'
        );
        self::assertSame(
            5,
            substr_count($html, 'ironcart-severity-circle ironcart-severity-circle--'),
            'every severity must render exactly one circle'
        );
    }

    public function testRendersEveryKnownSeverityModifier(): void
    {
        $html = SeverityIndicatorRow::render([]);
        foreach (['critical', 'high', 'medium', 'low', 'info'] as $modifier) {
            self::assertStringContainsString(
                'ironcart-severity-circle--' . $modifier,
                $html,
                "missing modifier for severity `{$modifier}`"
            );
        }
    }

    public function testZeroCountSeveritiesGetEmptyModifier(): void
    {
        $html = SeverityIndicatorRow::render([
            Severity::CRITICAL => 0,
            Severity::HIGH     => 0,
            Severity::MEDIUM   => 0,
            Severity::LOW      => 0,
            Severity::INFO     => 0,
        ]);

        self::assertSame(
            5,
            substr_count($html, 'ironcart-severity-circle--empty'),
            'all five severities must carry the empty modifier when count is 0'
        );
    }

    public function testNonZeroSeverityDoesNotGetEmptyModifier(): void
    {
        $html = SeverityIndicatorRow::render([
            Severity::CRITICAL => 1,
            Severity::HIGH     => 0,
            Severity::MEDIUM   => 0,
            Severity::LOW      => 0,
            Severity::INFO     => 0,
        ]);

        // Exactly one severity (critical) is non-zero, so exactly four
        // should carry the empty modifier.
        self::assertSame(
            4,
            substr_count($html, 'ironcart-severity-circle--empty'),
            'only zero-count severities should carry the empty modifier'
        );
    }

    public function testCountsAreRenderedInsideCircles(): void
    {
        $html = SeverityIndicatorRow::render([
            Severity::CRITICAL => 7,
            Severity::HIGH     => 0,
            Severity::MEDIUM   => 12,
            Severity::LOW      => 0,
            Severity::INFO     => 0,
        ]);

        self::assertStringContainsString('<span class="ironcart-severity-count">7</span>', $html);
        self::assertStringContainsString('<span class="ironcart-severity-count">12</span>', $html);
        // Zeros also render — alignment matters more than "blank slot"
        // here because the empty modifier already mutes the visual.
        self::assertStringContainsString('<span class="ironcart-severity-count">0</span>', $html);
    }

    public function testTooltipsAreCapitalisedAndIncludeCount(): void
    {
        $html = SeverityIndicatorRow::render([
            Severity::CRITICAL => 3,
            Severity::HIGH     => 0,
        ]);

        self::assertStringContainsString('title="Critical: 3"', $html);
        self::assertStringContainsString('title="High: 0"', $html);
        // aria-label mirrors the title for screen-reader parity.
        self::assertStringContainsString('aria-label="Critical: 3"', $html);
    }

    public function testRowWrapperCarriesGroupRoleForAccessibility(): void
    {
        $html = SeverityIndicatorRow::render([]);
        self::assertStringContainsString('class="ironcart-severity-row"', $html);
        self::assertStringContainsString('role="group"', $html);
        self::assertStringContainsString('aria-label="Severity totals"', $html);
    }

    public function testMissingSeverityKeyDefaultsToZeroCount(): void
    {
        // Belt-and-suspenders: ScanRunDataProvider already normalises
        // the summary_json shape, but the renderer must not blow up if
        // a severity key is missing entirely.
        $html = SeverityIndicatorRow::render([Severity::CRITICAL => 5]);

        // All five slots still rendered.
        self::assertSame(5, substr_count($html, 'ironcart-severity-slot'));
        // The four missing severities get the empty modifier + 0 count.
        self::assertSame(4, substr_count($html, 'ironcart-severity-circle--empty'));
        self::assertStringContainsString('<span class="ironcart-severity-count">5</span>', $html);
    }

    public function testNonIntegerCountIsCoercedSafely(): void
    {
        // ScanRunDataProvider serialises summary_json from JSON, which
        // gives ints — but a third party could feasibly hand us a
        // string. Render must coerce, not crash, and must not leak the
        // raw value into the markup.
        $html = SeverityIndicatorRow::render([
            Severity::CRITICAL => '4',
            Severity::HIGH     => null,
            Severity::MEDIUM   => 0,
            Severity::LOW      => 0,
            Severity::INFO     => 0,
        ]);

        self::assertStringContainsString('<span class="ironcart-severity-count">4</span>', $html);
        // null and missing both become 0 — and pick up the empty modifier.
        self::assertStringContainsString('title="High: 0"', $html);
    }

    public function testRendersExactlyOneRowWrapper(): void
    {
        // Guards against accidental double-rendering / block stacking
        // — the whole point of this rework is one row per cell.
        $html = SeverityIndicatorRow::render([]);
        self::assertSame(
            1,
            substr_count($html, 'class="ironcart-severity-row"'),
            'cell must contain exactly one row wrapper'
        );
    }
}
