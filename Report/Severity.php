<?php

/**
 * IronCart_Scan — severity vocabulary.
 *
 * Centralises the severity strings that appear in the v0 report schema so
 * future check classes cannot drift away from the documented vocabulary.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Report;

/**
 * Severity vocabulary for findings and report summaries.
 *
 * The ordering of {@see Severity::ALL} is significant: it controls both
 * the summary key order in the JSON report and the rendering order in the
 * text report.
 */
final class Severity
{
    public const CRITICAL = 'critical';
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const LOW = 'low';
    public const INFO = 'info';

    /**
     * All severities, highest-first.
     *
     * @var list<string>
     */
    public const ALL = [
        self::CRITICAL,
        self::HIGH,
        self::MEDIUM,
        self::LOW,
        self::INFO,
    ];

    private function __construct()
    {
    }

    /**
     * Return whether the given string is a recognised severity.
     */
    public static function isValid(string $severity): bool
    {
        return in_array($severity, self::ALL, true);
    }
}
