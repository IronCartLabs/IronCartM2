<?php

/**
 * IronCart_Scan — DynamicIncludeCheck (IC-052) unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\CodeSmell;

use IronCart\Scan\Check\CodeSmell\DynamicIncludeCheck;
use IronCart\Scan\Report\Severity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\CodeSmell\DynamicIncludeCheck
 */
class DynamicIncludeCheckTest extends TestCase
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

    public function testIncludeWithVariableFlagged(): void
    {
        $this->sandbox->writeFile(
            'app/code/Acme/Bad/Loader.php',
            "<?php\nclass Loader { public function go(string \$path) { include \$path; } }\n"
        );

        $findings = (new DynamicIncludeCheck($this->sandbox->walker()))->run();

        self::assertCount(1, $findings);
        self::assertSame(DynamicIncludeCheck::ID, $findings[0]['id']);
        self::assertSame(Severity::HIGH, $findings[0]['severity']);
    }

    public function testAllFourFormsFlagged(): void
    {
        // include / include_once / require / require_once all qualify.
        $this->sandbox->writeFile(
            'app/code/Acme/Bad/AllFour.php',
            <<<PHP
            <?php
            class AllFour {
                public function a(\$p) { include \$p; }
                public function b(\$p) { include_once \$p; }
                public function c(\$p) { require \$p; }
                public function d(\$p) { require_once \$p; }
            }
            PHP
        );

        $findings = (new DynamicIncludeCheck($this->sandbox->walker()))->run();

        self::assertCount(4, $findings);
    }

    public function testParenthesisedDynamicIncludeFlagged(): void
    {
        $this->sandbox->writeFile(
            'app/code/Acme/Bad/Paren.php',
            "<?php\nclass Paren { public function go(string \$p) { include(\$p); } }\n"
        );

        $findings = (new DynamicIncludeCheck($this->sandbox->walker()))->run();

        self::assertCount(1, $findings);
    }

    public function testStaticIncludeOfLiteralIgnored(): void
    {
        $this->sandbox->writeFile(
            'app/code/Acme/Ok/Static.php',
            "<?php\nrequire __DIR__ . '/_bootstrap.php';\nclass StaticOk {}\n"
        );

        // __DIR__ . '...' is a constant expression starting with a
        // T_DIR magic-constant, not a T_VARIABLE — correctly ignored.
        self::assertSame([], (new DynamicIncludeCheck($this->sandbox->walker()))->run());
    }

    public function testIncludeLiteralInsideStringIgnored(): void
    {
        $this->sandbox->writeFile(
            'app/code/Acme/Strings/Doc.php',
            "<?php\nclass Doc { public function note(): string { return 'do not use include \$path in app/code'; } }\n"
        );

        self::assertSame([], (new DynamicIncludeCheck($this->sandbox->walker()))->run());
    }

    public function testVendorTreeNotWalked(): void
    {
        $this->sandbox->writeFile(
            'vendor/acme/lib/Bad.php',
            "<?php\nclass Bad { public function go(\$p) { include \$p; } }\n"
        );

        self::assertSame([], (new DynamicIncludeCheck($this->sandbox->walker()))->run());
    }
}
