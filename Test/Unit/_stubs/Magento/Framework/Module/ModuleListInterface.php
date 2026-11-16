<?php

/**
 * Unit-test stub of Magento\Framework\Module\ModuleListInterface (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Framework\Module;

interface ModuleListInterface
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAll();

    /**
     * @param string $name
     * @return array<string, mixed>|null
     */
    public function getOne($name);

    /**
     * @return string[]
     */
    public function getNames();

    /**
     * @param string $name
     * @return bool
     */
    public function has($name);
}
