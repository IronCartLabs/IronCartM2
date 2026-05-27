<?php

/**
 * IronCart_Scan — MageModeCheck unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Runtime;

use IronCart\Scan\Check\Runtime\MageModeCheck;
use IronCart\Scan\Check\Runtime\MagentoModeReader;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

class MageModeCheckTest extends TestCase
{
    public function testNoFindingWhenModeIsProduction(): void
    {
        $reader = $this->readerReturning(State::MODE_PRODUCTION);

        $config = $this->createMock(ScopeConfigInterface::class);

        $check = new MageModeCheck($reader, $config);

        $this->assertSame([], $check->run());
    }

    public function testNoFindingWhenDeveloperModeOnLocalhost(): void
    {
        $reader = $this->readerReturning(State::MODE_DEVELOPER);

        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnMap([
            ['web/unsecure/base_url', 'store', null, 'http://localhost/'],
            ['web/secure/base_url', 'store', null, 'http://magento.test/'],
        ]);

        $check = new MageModeCheck($reader, $config);

        $this->assertSame([], $check->run());
    }

    public function testCriticalFindingWhenDeveloperModeOnPublicHost(): void
    {
        $reader = $this->readerReturning(State::MODE_DEVELOPER);

        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnMap([
            ['web/unsecure/base_url', 'store', null, 'https://shop.example.com/'],
            ['web/secure/base_url', 'store', null, 'https://shop.example.com/'],
        ]);

        $check = new MageModeCheck($reader, $config);

        $findings = $check->run();

        $this->assertCount(1, $findings);
        $this->assertSame(MageModeCheck::ID, $findings[0]['id']);
        $this->assertSame(Severity::CRITICAL, $findings[0]['severity']);
        $this->assertSame(State::MODE_DEVELOPER, $findings[0]['evidence']['mage_mode']);
    }

    public function testDefaultModeFromReaderSkipsCheck(): void
    {
        // When the underlying State::getMode() throws, MagentoModeReader
        // resolves to MODE_DEFAULT (pinned in MagentoModeReaderTest). The
        // IC-020 check only fires for MODE_DEVELOPER, so MODE_DEFAULT
        // must short-circuit to zero findings here.
        $reader = $this->readerReturning(State::MODE_DEFAULT);

        $config = $this->createMock(ScopeConfigInterface::class);

        $check = new MageModeCheck($reader, $config);

        $this->assertSame([], $check->run());
    }

    private function readerReturning(string $mode): MagentoModeReader
    {
        $reader = $this->createMock(MagentoModeReader::class);
        $reader->method('mode')->willReturn($mode);

        return $reader;
    }
}
