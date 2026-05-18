<?php

/**
 * IronCart_Scan — IC-911 unit tests.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Hyva;

use IronCart\Scan\Check\Hyva\CheckoutCspRegressionCheck;
use IronCart\Scan\Check\Hyva\HyvaDetector;
use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use IronCart\Scan\Report\Severity;
use Magento\Framework\Module\ModuleListInterface;
use PHPUnit\Framework\TestCase;

final class CheckoutCspRegressionCheckTest extends TestCase
{
    private string $magentoRoot = '';
    private string $manifestDir = '';

    protected function setUp(): void
    {
        $this->magentoRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'ironcart-scan-ic911-root-'
            . bin2hex(random_bytes(6));
        mkdir($this->magentoRoot . '/app/etc', 0o755, true);
        file_put_contents($this->magentoRoot . '/composer.lock', "{}\n");

        $this->manifestDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'ironcart-scan-ic911-manifest-'
            . bin2hex(random_bytes(6));
        mkdir($this->manifestDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->magentoRoot);
        $this->rrmdir($this->manifestDir);
    }

    public function testReturnsNoFindingsWhenHyvaNotDetected(): void
    {
        $check = new CheckoutCspRegressionCheck(
            $this->detector(false, []),
            $this->magentoRoot,
            $this->manifestDir
        );
        self::assertSame([], $check->run());
    }

    public function testReturnsNoFindingsWhenCheckoutPackageAbsent(): void
    {
        $check = new CheckoutCspRegressionCheck(
            $this->detector(true, ['hyva-themes/magento2-default-theme' => '1.3.6']),
            $this->magentoRoot,
            $this->manifestDir
        );
        self::assertSame([], $check->run());
    }

    public function testReturnsLowFindingWhenManifestMissingForVersion(): void
    {
        $this->writeCspWhitelist([
            'sha256-AAAA=', 'sha256-BBBB=',
        ]);

        $check = new CheckoutCspRegressionCheck(
            $this->detector(true, ['hyva-themes/magento2-hyva-checkout' => '99.0.0']),
            $this->magentoRoot,
            $this->manifestDir
        );
        $findings = $check->run();

        self::assertCount(1, $findings);
        self::assertSame(Severity::LOW, $findings[0]['severity']);
        self::assertSame('manifest_unavailable', $findings[0]['evidence']['status']);
    }

    public function testReturnsNoFindingsWhenAllHashesInManifest(): void
    {
        $this->writeManifest('1.1.16', ['sha256-AAAA=', 'sha256-BBBB=']);
        $this->writeCspWhitelist(['sha256-AAAA=', 'sha256-BBBB=']);

        $check = new CheckoutCspRegressionCheck(
            $this->detector(true, ['hyva-themes/magento2-hyva-checkout' => '1.1.16']),
            $this->magentoRoot,
            $this->manifestDir
        );
        self::assertSame([], $check->run());
    }

    public function testFlagsStaleHashesNotInManifest(): void
    {
        $this->writeManifest('1.1.16', ['sha256-AAAA=', 'sha256-BBBB=']);
        $this->writeCspWhitelist([
            'sha256-AAAA=',
            'sha256-BBBB=',
            'sha256-STALEXX=', // not in manifest
        ]);

        $check = new CheckoutCspRegressionCheck(
            $this->detector(true, ['hyva-themes/magento2-hyva-checkout' => '1.1.16']),
            $this->magentoRoot,
            $this->manifestDir
        );
        $findings = $check->run();

        self::assertCount(1, $findings);
        self::assertSame('IC-911', $findings[0]['id']);
        self::assertSame(Severity::MEDIUM, $findings[0]['severity']);
        self::assertSame(['sha256-STALEXX='], $findings[0]['evidence']['stale_hashes']);
        self::assertSame('1.1.16', $findings[0]['evidence']['installed_version']);
    }

    public function testReturnsNoFindingsWhenWhitelistMissing(): void
    {
        $this->writeManifest('1.1.16', ['sha256-AAAA=']);
        // No csp_whitelist.xml written.
        $check = new CheckoutCspRegressionCheck(
            $this->detector(true, ['hyva-themes/magento2-hyva-checkout' => '1.1.16']),
            $this->magentoRoot,
            $this->manifestDir
        );
        self::assertSame([], $check->run());
    }

    /**
     * @param list<string> $hashes
     */
    private function writeManifest(string $version, array $hashes): void
    {
        file_put_contents(
            $this->manifestDir . DIRECTORY_SEPARATOR . $version . '.json',
            json_encode(['version' => $version, 'hashes' => $hashes])
        );
    }

    /**
     * @param list<string> $hashes
     */
    private function writeCspWhitelist(array $hashes): void
    {
        $values = '';
        foreach ($hashes as $h) {
            $values .= sprintf(
                '<value type="hash" algorithm="sha256">%s</value>',
                htmlspecialchars($h, ENT_XML1)
            );
        }
        $xml = <<<XML
<?xml version="1.0"?>
<csp_whitelist xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <policies>
    <policy id="script-src">
      <values>{$values}</values>
    </policy>
  </policies>
</csp_whitelist>
XML;
        file_put_contents($this->magentoRoot . '/app/etc/csp_whitelist.xml', $xml);
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

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
