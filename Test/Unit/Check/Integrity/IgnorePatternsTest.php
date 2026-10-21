<?php

/**
 * IronCart_Scan — IgnorePatterns::fromLists() unit tests.
 *
 * Magento-free regression cover for #218: the in-memory factory used to
 * construct via `new self(__FILE__)`, which parsed this class's PHP source
 * as the JSON whitelist and always threw.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Test\Unit\Check\Integrity;

use IronCart\Scan\Check\Integrity\IgnorePatterns;
use PHPUnit\Framework\TestCase;

/**
 * @covers \IronCart\Scan\Check\Integrity\IgnorePatterns
 */
class IgnorePatternsTest extends TestCase
{
    public function testFromListsUsesOnlyTheGivenLists(): void
    {
        $patterns = IgnorePatterns::fromLists(['var/'], ['app/etc/env.php']);

        self::assertSame(['var/'], $patterns->prefixes());
        self::assertSame(['app/etc/env.php'], $patterns->exact());
        self::assertTrue($patterns->matches('var/log/system.log'));
        self::assertTrue($patterns->matches('app/etc/env.php'));
        self::assertFalse($patterns->matches('generated/code/Foo.php'));
    }

    public function testFromListsWithEmptyListsMatchesNothing(): void
    {
        $patterns = IgnorePatterns::fromLists([], []);

        self::assertSame([], $patterns->prefixes());
        self::assertSame([], $patterns->exact());
        self::assertFalse($patterns->matches('var/log/system.log'));
    }

    public function testFromListsDropsTraversalEntries(): void
    {
        $patterns = IgnorePatterns::fromLists(
            ['../', '/abs', "with\0null", 'pub/media/'],
            ['../escape', '/etc/passwd']
        );

        self::assertSame(['pub/media/'], $patterns->prefixes());
        self::assertSame([], $patterns->exact());
    }
}
