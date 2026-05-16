<?php

/**
 * IronCart_Scan — ShellExecCheck (IC-053) unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\CodeSmell;

use IronCart\Scan\Check\CodeSmell\ShellExecCheck;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\CodeSmell\ShellExecCheck
 */
class ShellExecCheckTest extends TestCase
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

    public function testShellExecFlagged(): void
    {
        $this->sandbox->writeFile(
            'app/code/Acme/Bad/Run.php',
            "<?php\nclass Run { public function go() { return shell_exec('ls'); } }\n"
        );

        $findings = (new ShellExecCheck($this->sandbox->walker()))->run();

        self::assertCount(1, $findings);
        self::assertSame(ShellExecCheck::ID, $findings[0]['id']);
        self::assertSame(Severity::HIGH, $findings[0]['severity']);
    }

    public function testAllSixNamedFunctionsFlagged(): void
    {
        $this->sandbox->writeFile(
            'app/code/Acme/Bad/AllSix.php',
            <<<PHP
            <?php
            class AllSix {
                public function a() { return shell_exec('id'); }
                public function b() { return exec('id'); }
                public function c() { passthru('id'); }
                public function d() { system('id'); }
                public function e() { return popen('id', 'r'); }
                public function f() { return proc_open('id', [], \$p); }
            }
            PHP
        );

        $findings = (new ShellExecCheck($this->sandbox->walker()))->run();

        self::assertCount(6, $findings);
    }

    public function testBacktickOperatorFlagged(): void
    {
        $this->sandbox->writeFile(
            'app/code/Acme/Bad/Backtick.php',
            "<?php\nclass Backtick { public function go(): string { return `whoami`; } }\n"
        );

        $findings = (new ShellExecCheck($this->sandbox->walker()))->run();

        self::assertCount(1, $findings);
    }

    public function testMethodCallNotFlagged(): void
    {
        // $this->exec(...) and Foo::exec(...) are NOT the global exec().
        $this->sandbox->writeFile(
            'app/code/Acme/Ok/Methods.php',
            <<<PHP
            <?php
            class Methods {
                public function a() { return \$this->exec('id'); }
                public function b() { return Foo::system('id'); }
                public function exec(\$c) { return \$c; }
            }
            class Foo { public static function system(\$c) { return \$c; } }
            PHP
        );

        self::assertSame([], (new ShellExecCheck($this->sandbox->walker()))->run());
    }

    public function testFunctionDefinitionNotFlagged(): void
    {
        // `function exec(...)` is a definition, not a call.
        $this->sandbox->writeFile(
            'app/code/Acme/Ok/Define.php',
            "<?php\nfunction exec(\$x) { return \$x; }\nclass Define {}\n"
        );

        self::assertSame([], (new ShellExecCheck($this->sandbox->walker()))->run());
    }

    public function testShellLiteralInsideStringIgnored(): void
    {
        $this->sandbox->writeFile(
            'app/code/Acme/Strings/Doc.php',
            "<?php\nclass Doc { public function note(): string { return 'avoid shell_exec() and backticks like `id`'; } }\n"
        );

        self::assertSame([], (new ShellExecCheck($this->sandbox->walker()))->run());
    }

    public function testNamespacedCallFlagged(): void
    {
        // `\shell_exec(...)` — explicit global-namespace call.
        $this->sandbox->writeFile(
            'app/code/Acme/Bad/Ns.php',
            "<?php\nnamespace Acme\\Bad;\nclass Ns { public function go() { return \\shell_exec('id'); } }\n"
        );

        $findings = (new ShellExecCheck($this->sandbox->walker()))->run();

        self::assertCount(1, $findings);
    }

    public function testVendorTreeNotWalked(): void
    {
        $this->sandbox->writeFile(
            'vendor/acme/lib/Bad.php',
            "<?php\nclass Bad { public function go() { return shell_exec('id'); } }\n"
        );

        self::assertSame([], (new ShellExecCheck($this->sandbox->walker()))->run());
    }
}
