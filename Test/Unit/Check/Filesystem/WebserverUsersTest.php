<?php

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Filesystem;

use IronCart\Scan\Check\Filesystem\WebserverUsers;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\Filesystem\WebserverUsers
 */
class WebserverUsersTest extends TestCase
{
    public function testFreeTierListIsTheCanonicalSeven(): void
    {
        // Pinning test — bumping this list is a posture change shared by IC-031
        // and IC-201. Update intentionally; do not silently extend.
        self::assertSame(
            [
                'www-data',
                'nginx',
                'apache',
                'apache2',
                'httpd',
                'http',
                'nobody',
            ],
            WebserverUsers::NAMES
        );
    }

    public function testReconTierListIsRootPlusFreeTier(): void
    {
        // The Recon list (IC-201) MUST be `root` followed by the free-tier
        // list verbatim — no drift, no reordering, no extras. If you need a
        // different ordering, fix it here and in {@see WebserverUsers}.
        self::assertSame(
            array_merge(['root'], WebserverUsers::NAMES),
            WebserverUsers::NAMES_INCLUDING_ROOT
        );
    }

    public function testReconTierListExtendsFreeTierWithRootOnly(): void
    {
        $extra = array_values(array_diff(
            WebserverUsers::NAMES_INCLUDING_ROOT,
            WebserverUsers::NAMES
        ));

        self::assertSame(['root'], $extra, 'IC-201 may only add `root` on top of IC-031');
    }
}
