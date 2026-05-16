<?php

/**
 * IronCart_Scan — upload runner outcome value object.
 *
 * Returned by {@see UploadRunner::run()} so the CLI shell can render
 * stdout/stderr without re-implementing the runner's category-to-message
 * mapping.
 *
 *   - `EXIT_OK` (0) — upload succeeded OR was correctly skipped via the
 *     opt-in gate. Either way, the wrapping scan run is healthy.
 *   - `EXIT_MISCONFIGURED` (2) — upload enabled but token missing,
 *     endpoint host doesn't match the allow-list, or some other operator-
 *     correctable state. Cron picks this up.
 *   - `EXIT_TRANSPORT` (3) — server unreachable / TLS failure / timeout.
 *   - `EXIT_SERVER` (4) — server returned a non-2xx response.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Upload;

/**
 * Outcome of an {@see UploadRunner::run()} call.
 */
final class UploadRunnerOutcome
{
    public const EXIT_OK = 0;
    public const EXIT_MISCONFIGURED = 2;
    public const EXIT_TRANSPORT = 3;
    public const EXIT_SERVER = 4;

    /**
     * @param int          $exitCode  One of the EXIT_* constants.
     * @param string       $stdout    Human-readable stdout message — printed verbatim.
     * @param string       $stderr    Human-readable stderr message — printed verbatim.
     * @param string|null  $viewUrl   `view_url` from a 2xx response, if any.
     */
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly ?string $viewUrl = null
    ) {
    }
}
