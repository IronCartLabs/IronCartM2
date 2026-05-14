<?php

/**
 * IronCart_Scan — check contract.
 *
 * Every scanner check implements this interface. v0 is intentionally minimal:
 * checks are pure functions of their environment and return zero or more
 * findings shaped against the {@see \IronCart\Scan\Report\ReportBuilder} v0
 * schema. Checks must be read-only and must not make outbound network calls.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check;

/**
 * Contract for a single scanner check.
 *
 * Implementations should be cheap to construct (DI-instantiable with no side
 * effects) and tolerant of hosts where optional PHP extensions or POSIX
 * functions are unavailable — degrade with an `info`-severity finding rather
 * than throwing.
 */
interface CheckInterface
{
    /**
     * Run the check and return its findings.
     *
     * @return list<array{
     *     id:string,
     *     title:string,
     *     severity:string,
     *     evidence:mixed,
     *     remediation_url:string
     * }>
     */
    public function run(): array;
}
