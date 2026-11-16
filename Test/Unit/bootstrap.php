<?php

/**
 * IronCart_Scan — PHPUnit bootstrap for the unit suite (#216).
 *
 * Loads the Composer autoloader, then appends a fallback autoloader for the
 * minimal Magento / PSR type stubs under `Test/Unit/_stubs/`.
 *
 * Why stubs: `magento/framework` and the `magento/module-*` packages only
 * ship from repo.magento.com, which needs credentials that fork PRs do not
 * get, and generated `*Factory` classes exist in no package at all. The
 * unit job therefore installs without them. Each stub declares only the
 * surface the unit tests and the code under test touch, with Magento's own
 * signatures, so PHPUnit can mock it and `extends` / `implements` resolve.
 * Real Magento behaviour is covered by the integration jobs.
 *
 * The fallback runs after Composer's autoloader (which is prepended), so on
 * a checkout that does have the real Magento packages installed, the real
 * classes win and the stubs are never loaded.
 *
 * `Test/Unit/_stubs` is `export-ignore`d in `.gitattributes`, so the stubs
 * never ship in the Packagist / Marketplace archives.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Magento\\') && !str_starts_with($class, 'Psr\\Log\\')) {
        return;
    }

    $file = __DIR__ . '/_stubs/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Global functions cannot be autoloaded. Magento's `__()` helper is
// declared here behind a function_exists() guard (#225).
require __DIR__ . '/_stubs/functions.php';
