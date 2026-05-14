<?php

/**
 * IronCart_Scan — ScanSession unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check;

use IronCart\Scan\Check\ScanSession;
use PHPUnit\Framework\TestCase;

class ScanSessionTest extends TestCase
{
    public function testDefaultsToPiiOff(): void
    {
        $this->assertFalse((new ScanSession())->includeUsernames());
    }

    public function testSetterFlipsTheFlag(): void
    {
        $session = new ScanSession();
        $session->setIncludeUsernames(true);
        $this->assertTrue($session->includeUsernames());

        $session->setIncludeUsernames(false);
        $this->assertFalse($session->includeUsernames());
    }
}
