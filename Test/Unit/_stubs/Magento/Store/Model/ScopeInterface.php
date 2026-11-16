<?php

/**
 * Unit-test stub of Magento\Store\Model\ScopeInterface (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Store\Model;

interface ScopeInterface
{
    public const SCOPE_STORES = 'stores';
    public const SCOPE_GROUPS = 'groups';
    public const SCOPE_WEBSITES = 'websites';
    public const SCOPE_STORE = 'store';
    public const SCOPE_GROUP = 'group';
    public const SCOPE_WEBSITE = 'website';
}
