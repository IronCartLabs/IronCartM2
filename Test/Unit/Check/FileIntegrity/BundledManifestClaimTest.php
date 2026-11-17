<?php

/**
 * IronCart_Scan — bundled-manifest claim test.
 *
 * Pins what the README says about IC-070 / IC-072 reference manifests to
 * what `etc/manifests/` really contains. Every release up to v1.6.3 told
 * merchants core files were checked "against bundled reference manifests"
 * while shipping none (IronCartLabs/IronCartM2#236), so both checks only
 * ever returned their "manifest not available" finding.
 *
 * The test reads the real module tree through the repositories' default
 * directory, the same path the scanner uses at runtime.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\FileIntegrity;

use IronCart\Scan\Check\FileIntegrity\ComposerLockManifestRepository;
use IronCart\Scan\Check\FileIntegrity\ManifestRepository;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\FileIntegrity\ManifestRepository
 * @covers \IronCart\Scan\Check\FileIntegrity\ComposerLockManifestRepository
 */
class BundledManifestClaimTest extends TestCase
{
    private const README = __DIR__ . '/../../../../README.md';

    public function testReadmeDoesNotClaimBundledReferenceManifestsWhileNoneShip(): void
    {
        $core = (new ManifestRepository())->bundledVersions();
        $composer = (new ComposerLockManifestRepository())->bundledVersions();
        self::assertArrayHasKey('community', $core);
        self::assertArrayHasKey('community', $composer);

        if (array_filter($core) !== [] && array_filter($composer) !== []) {
            // Both families ship, so the claim is true: nothing to guard.
            return;
        }

        $readme = file_get_contents(self::README);
        self::assertIsString($readme, 'README.md must be readable');
        self::assertDoesNotMatchRegularExpression(
            '/bundled\s+(reference\s+)?manifests?/i',
            $readme,
            'README.md says reference manifests are bundled, but etc/manifests/ ships none for IC-070 / IC-072.'
            . ' Commit the generated manifests for both families, or keep the README wording honest.'
        );
    }
}
