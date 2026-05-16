<?php

/**
 * IronCart_Scan — UnserializeUntrustedCheck (IC-051) unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\CodeSmell;

use IronCart\Scan\Check\CodeSmell\UnserializeUntrustedCheck;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\CodeSmell\UnserializeUntrustedCheck
 */
class UnserializeUntrustedCheckTest extends TestCase
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

    public function testUnserializeOfRequestFlagged(): void
    {
        $this->sandbox->writeFile(
            'app/code/Acme/Bad/Hydrate.php',
            "<?php\nclass Hydrate { public function run() { return unserialize(\$_REQUEST['payload']); } }\n"
        );

        $findings = (new UnserializeUntrustedCheck($this->sandbox->walker()))->run();

        self::assertCount(1, $findings);
        self::assertSame(UnserializeUntrustedCheck::ID, $findings[0]['id']);
        self::assertSame(Severity::CRITICAL, $findings[0]['severity']);
    }

    public function testUnserializeOfGetPostCookieAllFlagged(): void
    {
        $this->sandbox->writeFile(
            'app/code/Acme/Bad/AllSuperglobals.php',
            <<<PHP
            <?php
            class AllSuperglobals {
                public function g() { return unserialize(\$_GET['x']); }
                public function p() { return unserialize(\$_POST['x']); }
                public function c() { return unserialize(\$_COOKIE['x']); }
            }
            PHP
        );

        $findings = (new UnserializeUntrustedCheck($this->sandbox->walker()))->run();

        self::assertCount(3, $findings);
    }

    public function testUnserializeOfTrustedVariableIgnored(): void
    {
        // unserialize() of an internal variable is out of scope for the
        // strict pattern. The taint chain may still be a problem, but
        // IC-051 deliberately doesn't try to trace it (see issue body).
        $this->sandbox->writeFile(
            'app/code/Acme/Ok/Local.php',
            "<?php\nclass Local { public function load(string \$blob) { return unserialize(\$blob); } }\n"
        );

        self::assertSame([], (new UnserializeUntrustedCheck($this->sandbox->walker()))->run());
    }

    public function testUnserializeLiteralInsideStringIgnored(): void
    {
        $this->sandbox->writeFile(
            'app/code/Acme/Strings/Doc.php',
            "<?php\nclass Doc { public function note(): string { return 'never call unserialize(\$_REQUEST) on input'; } }\n"
        );

        self::assertSame([], (new UnserializeUntrustedCheck($this->sandbox->walker()))->run());
    }

    public function testVendorTreeNotWalked(): void
    {
        $this->sandbox->writeFile(
            'vendor/acme/lib/Bad.php',
            "<?php\nclass Bad { public function run() { return unserialize(\$_REQUEST['x']); } }\n"
        );

        self::assertSame([], (new UnserializeUntrustedCheck($this->sandbox->walker()))->run());
    }

    public function testMethodCallNotFlagged(): void
    {
        // $this->unserialize($_REQUEST['x']) is a method call, not the
        // global function. Out of scope.
        $this->sandbox->writeFile(
            'app/code/Acme/Ok/Method.php',
            "<?php\nclass Method { public function r() { return \$this->unserialize(\$_REQUEST['x']); } public function unserialize(\$x) { return \$x; } }\n"
        );

        self::assertSame([], (new UnserializeUntrustedCheck($this->sandbox->walker()))->run());
    }
}
