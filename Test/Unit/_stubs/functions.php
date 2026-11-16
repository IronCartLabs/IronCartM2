<?php

/**
 * Unit-test stub of Magento's global `__()` translation helper (#225).
 *
 * Code under test calls `__()` (e.g. ScanCommand::configure()). Magento
 * defines the helper itself, so the guard below lets the real one win on a
 * checkout that loads it. Required by Test/Unit/bootstrap.php. Not shipped
 * (export-ignore).
 */

declare(strict_types=1);

if (!function_exists('__')) {
    // phpcs:disable
    /**
     * Same argument handling as Magento's helper: the first argument is the
     * text, the rest are placeholder values (or one array of them).
     *
     * @param mixed ...$argc
     * @return \Magento\Framework\Phrase
     */
    function __(...$argc)
    {
        $text = array_shift($argc);
        if (!empty($argc) && is_array($argc[0])) {
            $argc = $argc[0];
        }

        return new \Magento\Framework\Phrase($text, $argc);
    }
    // phpcs:enable
}
