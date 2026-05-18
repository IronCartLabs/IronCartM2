<?php

/**
 * IronCart_Scan — IC-912 unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Hyva;

use IronCart\Scan\Check\Hyva\HyvaDetector;
use IronCart\Scan\Check\Hyva\HyvaModuleDriftCheck;
use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use IronCart\Scan\Report\Severity;
use Magento\Framework\Module\ModuleListInterface;
use PHPUnit\Framework\TestCase;

final class HyvaModuleDriftCheckTest extends TestCase
{
    private string $manifestDir = '';

    protected function setUp(): void
    {
        $this->manifestDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'ironcart-scan-ic912-'
            . bin2hex(random_bytes(6));
        mkdir($this->manifestDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->manifestDir)) {
            return;
        }
        foreach (scandir($this->manifestDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            @unlink($this->manifestDir . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($this->manifestDir);
    }

    public function testReturnsNoFindingsWhenHyvaNotDetected(): void
    {
        $this->writeManifest([
            'hyva-themes/magento2-default-theme' => ['min_version' => '1.3.6'],
        ]);
        $check = new HyvaModuleDriftCheck(
            $this->detector(false, []),
            $this->manifestDir
        );
        self::assertSame([], $check->run());
    }

    public function testReturnsNoFindingsWhenAllPackagesAboveFloor(): void
    {
        $this->writeManifest([
            'hyva-themes/magento2-default-theme' => ['min_version' => '1.3.6'],
            'hyva-themes/magento2-hyva-checkout' => ['min_version' => '1.1.16'],
        ]);
        $check = new HyvaModuleDriftCheck(
            $this->detector(true, [
                'hyva-themes/magento2-default-theme' => '1.3.6',
                'hyva-themes/magento2-hyva-checkout' => '1.2.0',
            ]),
            $this->manifestDir
        );
        self::assertSame([], $check->run());
    }

    public function testFlagsPackagesBelowSecurityFloorAsHigh(): void
    {
        $this->writeManifest([
            'hyva-themes/magento2-hyva-checkout' => [
                'min_version' => '1.1.16',
                'security' => true,
                'note' => 'XSS regression fix',
            ],
        ]);
        $check = new HyvaModuleDriftCheck(
            $this->detector(true, [
                'hyva-themes/magento2-hyva-checkout' => '1.1.10',
            ]),
            $this->manifestDir
        );
        $findings = $check->run();

        self::assertCount(1, $findings);
        self::assertSame('IC-912', $findings[0]['id']);
        self::assertSame(Severity::HIGH, $findings[0]['severity']);
        self::assertSame('hyva-themes/magento2-hyva-checkout', $findings[0]['evidence']['package']);
        self::assertSame('1.1.10', $findings[0]['evidence']['installed_version']);
        self::assertSame('1.1.16', $findings[0]['evidence']['min_version']);
        self::assertTrue($findings[0]['evidence']['security']);
    }

    public function testNonSecurityFloorIsMediumSeverity(): void
    {
        $this->writeManifest([
            'hyva-themes/magento2-graphql-tokens' => [
                'min_version' => '1.0.5',
                'security' => false,
            ],
        ]);
        $check = new HyvaModuleDriftCheck(
            $this->detector(true, [
                'hyva-themes/magento2-graphql-tokens' => '1.0.3',
            ]),
            $this->manifestDir
        );
        $findings = $check->run();

        self::assertCount(1, $findings);
        self::assertSame(Severity::MEDIUM, $findings[0]['severity']);
        self::assertFalse($findings[0]['evidence']['security']);
    }

    public function testPackagesWithoutManifestRowAreSilentlySkipped(): void
    {
        $this->writeManifest([
            'hyva-themes/magento2-hyva-checkout' => ['min_version' => '1.1.16'],
        ]);
        $check = new HyvaModuleDriftCheck(
            $this->detector(true, [
                'hyva-themes/magento2-some-unknown-package' => '0.1.0',
            ]),
            $this->manifestDir
        );
        self::assertSame([], $check->run());
    }

    public function testReturnsEmptyWhenManifestMissing(): void
    {
        // No manifest written.
        $check = new HyvaModuleDriftCheck(
            $this->detector(true, [
                'hyva-themes/magento2-default-theme' => '1.0.0',
            ]),
            $this->manifestDir
        );
        self::assertSame([], $check->run());
    }

    /**
     * @param array<string,array<string,mixed>> $packages
     */
    private function writeManifest(array $packages): void
    {
        file_put_contents(
            $this->manifestDir . DIRECTORY_SEPARATOR . HyvaModuleDriftCheck::MANIFEST_FILENAME,
            json_encode(['packages' => $packages])
        );
    }

    /**
     * @param array<string,string> $hyvaPackages
     */
    private function detector(bool $detected, array $hyvaPackages): HyvaDetector
    {
        return new class ($detected, $hyvaPackages) extends HyvaDetector {
            /** @param array<string,string> $hyvaPackages */
            public function __construct(
                private readonly bool $detected,
                private readonly array $hyvaPackages
            ) {
                parent::__construct(
                    new class implements ModuleListInterface {
                        public function getAll()
                        {
                            return [];
                        }
                        public function getOne($name)
                        {
                            return null;
                        }
                        public function getNames()
                        {
                            return [];
                        }
                        public function has($name)
                        {
                            return false;
                        }
                    },
                    new ComposerLockReader(null)
                );
            }
            public function isDetected(): bool
            {
                return $this->detected;
            }
            public function hyvaPackages(): array
            {
                return $this->hyvaPackages;
            }
            public function detect(): array
            {
                return [
                    'detected' => $this->detected,
                    'signals' => ['module' => $this->detected, 'composer' => $this->hyvaPackages !== []],
                    'hyva_packages' => $this->hyvaPackages,
                ];
            }
        };
    }
}
