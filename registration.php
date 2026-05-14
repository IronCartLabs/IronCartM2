<?php

/**
 * IronCart_Scan module registration.
 *
 * Registers the module with Magento's component registrar so that it can be
 * discovered by `bin/magento module:status` and enabled via
 * `bin/magento module:enable IronCart_Scan`.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'IronCart_Scan',
    __DIR__
);
