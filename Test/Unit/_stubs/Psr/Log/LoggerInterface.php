<?php

/**
 * Unit-test stub of Psr\Log\LoggerInterface (#216).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Psr\Log;

/**
 * psr/log 1.x signatures (untyped), which every 1.x-3.x implementer satisfies.
 */
interface LoggerInterface
{
    /**
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function emergency($message, array $context = []);

    /**
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function alert($message, array $context = []);

    /**
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function critical($message, array $context = []);

    /**
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function error($message, array $context = []);

    /**
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function warning($message, array $context = []);

    /**
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function notice($message, array $context = []);

    /**
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function info($message, array $context = []);

    /**
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function debug($message, array $context = []);

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function log($level, $message, array $context = []);
}
