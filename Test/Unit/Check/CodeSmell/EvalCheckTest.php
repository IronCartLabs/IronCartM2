<?php

/**
 * IronCart_Scan — EvalCheck (IC-050) unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\CodeSmell;

use IronCart\Scan\Check\CodeSmell\EvalCheck;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\CodeSmell\EvalCheck
 * @covers \IronCart\Scan\Check\CodeSmell\AbstractCodeSmellCheck
 * @covers \IronCart\Scan\Check\CodeSmell\AppCodeWalker
 * @covers \IronCart\Scan\Check\CodeSmell\TokenScanner
 */
class EvalCheckTest extends TestCase
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

    public function testCleanAppCodeReturnsNoFindings(): void
    {
        $this->sandbox->writeFile(
            'app/code/Acme/Clean/Block/Hello.php',
            "<?php\nnamespace Acme\\Clean\\Block;\nclass Hello { public function ok(): string { return 'hi'; } }\n"
        );

        self::assertSame([], (new EvalCheck($this->sandbox->walker()))->run());
    }

    public function testEvalInvocationFlaggedCritical(): void
    {
        $this->sandbox->writeFile(
            'app/code/Acme/Bad/Block/Pwn.php',
            "<?php\nnamespace Acme\\Bad\\Block;\nclass Pwn { public function run(string \$x): void { eval(\$x); } }\n"
        );

        $findings = (new EvalCheck($this->sandbox->walker()))->run();

        self::assertCount(1, $findings);
        self::assertSame(EvalCheck::ID, $findings[0]['id']);
        self::assertSame(Severity::CRITICAL, $findings[0]['severity']);
        self::assertSame(3, $findings[0]['evidence']['line']);
        self::assertStringContainsString('eval(', $findings[0]['evidence']['snippet']);
    }

    public function testEvalInsideStringLiteralIgnored(): void
    {
        // The literal text "eval(" inside a string MUST NOT be flagged.
        // This is the property the token-based detector buys us — a
        // regex over source would false-positive here.
        $this->sandbox->writeFile(
            'app/code/Acme/Strings/Logger.php',
            "<?php\nnamespace Acme\\Strings;\nclass Logger { public function log(): string { return 'avoid using eval() in app/code'; } }\n"
        );

        self::assertSame([], (new EvalCheck($this->sandbox->walker()))->run());
    }

    public function testEvalInsideCommentIgnored(): void
    {
        $this->sandbox->writeFile(
            'app/code/Acme/Comments/Doc.php',
            "<?php\nnamespace Acme\\Comments;\n// Reminder: do not eval() user input.\nclass Doc {}\n"
        );

        self::assertSame([], (new EvalCheck($this->sandbox->walker()))->run());
    }

    public function testVendorTreeNotWalked(): void
    {
        // Files outside `app/code/` are out of scope. IC-001/IC-002
        // (patch level) cover composer-managed dependencies.
        $this->sandbox->writeFile(
            'vendor/acme/lib/Bad.php',
            "<?php\nclass Bad { public function run(string \$x): void { eval(\$x); } }\n"
        );

        self::assertSame([], (new EvalCheck($this->sandbox->walker()))->run());
    }

    public function testMultipleEvalCallsAllReported(): void
    {
        $this->sandbox->writeFile(
            'app/code/Acme/Bad/Multi.php',
            "<?php\nclass Multi {\n    public function a(\$x) { eval(\$x); }\n    public function b(\$x) { eval(\$x); }\n}\n"
        );

        $findings = (new EvalCheck($this->sandbox->walker()))->run();

        self::assertCount(2, $findings);
        self::assertSame(3, $findings[0]['evidence']['line']);
        self::assertSame(4, $findings[1]['evidence']['line']);
    }
}
