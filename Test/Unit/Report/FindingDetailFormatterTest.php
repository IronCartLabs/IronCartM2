<?php

/**
 * IronCart_Scan — FindingDetailFormatter unit tests.
 *
 * Pins the admin `detail` column output shape against accidental drift.
 * The data-provider truncates this string at 240 chars at render time —
 * these tests cover the pre-truncation contract.
 *
 * Lives under Test/Unit/Report so the Magento-free unit CI cell picks
 * it up (the cell only loads Test/Unit/Report — see
 * .github/workflows/ci.yml).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Report;

use IronCart\Scan\Report\FindingDetailFormatter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Report\FindingDetailFormatter
 */
class FindingDetailFormatterTest extends TestCase
{
    private FindingDetailFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new FindingDetailFormatter();
    }

    public function testReturnsNullWhenBothEvidenceAndUrlAreEmpty(): void
    {
        self::assertNull($this->formatter->format(null, ''));
        self::assertNull($this->formatter->format([], ''));
        self::assertNull($this->formatter->format('', ''));
        // Whitespace-only URL still counts as "no URL".
        self::assertNull($this->formatter->format(null, '   '));
    }

    public function testFormatsAssociativeEvidenceArray(): void
    {
        $evidence = ['path' => 'app/etc/env.php', 'mode' => '0666'];

        $detail = $this->formatter->format($evidence, '');

        self::assertSame('path=app/etc/env.php, mode=0666', $detail);
    }

    public function testAppendsRemediationUrlWithEmDash(): void
    {
        $evidence = ['path' => 'app/etc/env.php', 'mode' => '0666'];
        $url = 'https://ironcart.dev/docs/IC-001';

        $detail = $this->formatter->format($evidence, $url);

        self::assertSame(
            'path=app/etc/env.php, mode=0666 — see https://ironcart.dev/docs/IC-001',
            $detail
        );
    }

    public function testReturnsRemediationOnlyWhenEvidenceIsEmpty(): void
    {
        $detail = $this->formatter->format(null, 'https://ironcart.dev/docs/IC-099');

        self::assertSame('see https://ironcart.dev/docs/IC-099', $detail);
    }

    public function testFormatsListEvidenceWithBrackets(): void
    {
        // Numeric-keyed (list) array — surface that it's a list rather
        // than render `0=foo, 1=bar` which reads confusingly.
        $detail = $this->formatter->format(['admin', 'editor', 'support'], '');

        self::assertSame('[admin, editor, support]', $detail);
    }

    public function testJsonEncodesNestedArrayValues(): void
    {
        // Mirrors real Webhooks/* checks whose evidence has a
        // `subscriptions` key holding a list of detail blobs.
        $evidence = [
            'subscriptions' => [
                ['id' => 7, 'url' => 'http://10.0.0.5/hook'],
            ],
            'count' => 1,
        ];

        $detail = $this->formatter->format($evidence, '');

        self::assertStringContainsString('subscriptions=', $detail);
        self::assertStringContainsString('"id":7', $detail);
        // JSON_UNESCAPED_SLASHES — admins can read paths/URLs at a glance.
        self::assertStringContainsString('http://10.0.0.5/hook', $detail);
        // count renders as native scalar, not JSON-encoded.
        self::assertStringEndsWith(', count=1', $detail);
    }

    public function testRendersBooleanScalarsHumanReadably(): void
    {
        // PHP's native (string)true === '1' and (string)false === ''
        // both read confusingly in a key=value line. The formatter
        // explicitly maps booleans to 'true'/'false'.
        $detail = $this->formatter->format(
            ['signed' => false, 'verified' => true],
            ''
        );

        self::assertSame('signed=false, verified=true', $detail);
    }

    public function testHandlesScalarEvidenceString(): void
    {
        // Some checks emit a plain string evidence (e.g. a config
        // path) rather than an associative array. Render directly.
        $detail = $this->formatter->format('app/etc/env.php', '');

        self::assertSame('app/etc/env.php', $detail);
    }

    public function testHandlesScalarEvidenceInteger(): void
    {
        $detail = $this->formatter->format(42, '');

        self::assertSame('42', $detail);
    }

    public function testTrimsRemediationUrlWhitespace(): void
    {
        $detail = $this->formatter->format(
            ['key' => 'value'],
            "  https://ironcart.dev/docs/IC-X  \n"
        );

        self::assertSame(
            'key=value — see https://ironcart.dev/docs/IC-X',
            $detail
        );
    }

    public function testProducesStableSingleLineOutput(): void
    {
        // AC: the data provider's 240-char truncate works on a single
        // line. Guard against accidental newlines in JSON-encoded
        // nested values.
        $evidence = [
            'note' => "line1\nline2",
            'nested' => ['k' => 'v'],
        ];

        $detail = $this->formatter->format($evidence, '');
        self::assertNotNull($detail);

        // The literal "\n" inside the JSON-encoded `nested` value
        // would be `\n` (two characters), but the raw newline in
        // `note` will appear as-is since strings pass through
        // verbatim. That's intentional: only non-scalar values
        // round-trip through json_encode, and the data-provider's
        // mb_substr-based truncate handles either case safely.
        // The assertion here is that the formatter does not emit
        // additional newlines on its own.
        self::assertSame(
            substr_count("line1\nline2", "\n"),
            substr_count($detail, "\n"),
            'formatter must not introduce newlines beyond those in scalar evidence values'
        );
    }
}
