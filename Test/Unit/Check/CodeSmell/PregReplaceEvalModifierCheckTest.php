<?php

/**
 * IronCart_Scan — PregReplaceEvalModifierCheck (IC-054) unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\CodeSmell;

use IronCart\Scan\Check\CodeSmell\PregReplaceEvalModifierCheck;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\CodeSmell\PregReplaceEvalModifierCheck
 */
class PregReplaceEvalModifierCheckTest extends TestCase
{
    private CodeSmellSandbox $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = new CodeSmellSandbox();
    }

    protected function tearDown(): void
    {
        $this->sandbox->cleanup();
    }

    public function testSlashEModifierFlagged(): void
    {
        $this->sandbox->writeFile(
            'app/code/Acme/Bad/Replace.php',
            "<?php\nclass Replace { public function go(string \$s): string { return preg_replace('/foo/e', 'phpinfo()', \$s); } }\n"
        );

        $findings = (new PregReplaceEvalModifierCheck($this->sandbox->walker()))->run();

        self::assertCount(1, $findings);
        self::assertSame(PregReplaceEvalModifierCheck::ID, $findings[0]['id']);
        self::assertSame(Severity::CRITICAL, $findings[0]['severity']);
    }

    public function testHashDelimiterEModifierFlagged(): void
    {
        $this->sandbox->writeFile(
            'app/code/Acme/Bad/Hash.php',
            "<?php\nclass Hash { public function go(string \$s): string { return preg_replace('#bar#e', 'evil()', \$s); } }\n"
        );

        self::assertCount(1, (new PregReplaceEvalModifierCheck($this->sandbox->walker()))->run());
    }

    public function testTildeDelimiterWithMultipleModifiersFlagged(): void
    {
        // `~...~ie` — `i` (case-insensitive) + `e` (eval). Still RCE.
        $this->sandbox->writeFile(
            'app/code/Acme/Bad/Tilde.php',
            "<?php\nclass Tilde { public function go(string \$s): string { return preg_replace('~baz~ie', 'evil()', \$s); } }\n"
        );

        self::assertCount(1, (new PregReplaceEvalModifierCheck($this->sandbox->walker()))->run());
    }

    public function testCurlyBracketDelimiterFlagged(): void
    {
        $this->sandbox->writeFile(
            'app/code/Acme/Bad/Curly.php',
            "<?php\nclass Curly { public function go(string \$s): string { return preg_replace('{qux}e', 'evil()', \$s); } }\n"
        );

        self::assertCount(1, (new PregReplaceEvalModifierCheck($this->sandbox->walker()))->run());
    }

    public function testCleanPregReplaceWithoutEIgnored(): void
    {
        $this->sandbox->writeFile(
            'app/code/Acme/Ok/Clean.php',
            "<?php\nclass Clean { public function go(string \$s): string { return preg_replace('/foo/i', 'bar', \$s); } }\n"
        );

        self::assertSame([], (new PregReplaceEvalModifierCheck($this->sandbox->walker()))->run());
    }

    public function testPathWithSlashIgnoredAsModifier(): void
    {
        // `/var/log/example.txt` must NOT be mistaken for an `/e` modifier
        // pattern. The modifier-letter guard rejects `xample.txt` as a
        // non-modifier suffix.
        $this->sandbox->writeFile(
            'app/code/Acme/Ok/Path.php',
            "<?php\nclass Path { public function go(string \$s): string { return preg_replace('/var/log/example.txt', 'x', \$s); } }\n"
        );

        self::assertSame([], (new PregReplaceEvalModifierCheck($this->sandbox->walker()))->run());
    }

    public function testEModifierLiteralInsideStringIgnored(): void
    {
        // The text "preg_replace('/foo/e', ...)" inside a string literal
        // MUST NOT trip the check — the entire thing is one
        // T_CONSTANT_ENCAPSED_STRING, not a real preg_replace call.
        $this->sandbox->writeFile(
            'app/code/Acme/Strings/Doc.php',
            "<?php\nclass Doc { public function note(): string { return 'never write preg_replace(\\'/foo/e\\', ...)'; } }\n"
        );

        self::assertSame([], (new PregReplaceEvalModifierCheck($this->sandbox->walker()))->run());
    }

    public function testVariablePatternIgnored(): void
    {
        // We can't statically analyse a variable pattern — out of scope
        // for the strict pattern list.
        $this->sandbox->writeFile(
            'app/code/Acme/Ok/Var.php',
            "<?php\nclass Variable { public function go(string \$pat, string \$s): string { return preg_replace(\$pat, 'x', \$s); } }\n"
        );

        self::assertSame([], (new PregReplaceEvalModifierCheck($this->sandbox->walker()))->run());
    }

    public function testVendorTreeNotWalked(): void
    {
        $this->sandbox->writeFile(
            'vendor/acme/lib/Bad.php',
            "<?php\nclass Bad { public function go(\$s) { return preg_replace('/foo/e', 'evil()', \$s); } }\n"
        );

        self::assertSame([], (new PregReplaceEvalModifierCheck($this->sandbox->walker()))->run());
    }
}
