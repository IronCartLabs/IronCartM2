<?php

/**
 * IronCart_Scan — continuous-monitoring upload cron.
 *
 * Magento cron entry point declared in `etc/crontab.xml` as
 * `ironcart_scan_upload_cron`. Runs `bin/magento ironcart:scan --upload`
 * on the operator-configured schedule (default `0 3 * * *` — daily at
 * 03:00 store time) when `ironcart_scan/cron/enabled` is set to `1`
 * (default: `0`).
 *
 * v4 of the continuous-monitoring loop, per IronCartLabs/IronCartM2#64:
 * the merchant store **pulls** scans on its own schedule and pushes the
 * results outbound to ironcart.dev. The merchant store does NOT accept
 * any inbound connections from ironcart.dev. This preserves the v3+
 * "outbound-network only on explicit opt-in" invariant from the tracking
 * epic ({@link https://github.com/IronCartLabs/IronCartWeb/issues/884}).
 *
 * Execution semantics:
 *
 *   - When `ironcart_scan/cron/enabled = 0` (default) — return immediately,
 *     do NOT enter `ScanEngineRunner::runAndReport()`, do NOT log noise.
 *   - When `ironcart_scan/cron/enabled = 1` — drive the full scan + upload
 *     pipeline (same path as the CLI), then log a single success or
 *     failure line to `var/log/ironcart_scan.log` and let the cron
 *     framework persist the exit-code via the underlying `cron_schedule`
 *     row's status column.
 *   - 402 (free-tier exhausted) is logged with the `upgrade_url` returned
 *     by the server and surfaces as a throwable so Magento's standard
 *     cron-failure surface picks it up. This is the only failure shape
 *     where the server body's `upgrade_url` is contractually safe to
 *     echo verbatim — see {@see UploadClient::extractUpgradeUrl()}.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Cron;

use IronCart\Scan\Check\License\UpgradeNagEmitter;
use IronCart\Scan\Check\Upload\UploadRunner;
use IronCart\Scan\Check\Upload\UploadRunnerOutcome;
use IronCart\Scan\Model\ScanEngineRunner;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Magento cron handler that runs the scan + upload pipeline on a schedule.
 *
 * Bound to {@see \IronCart\Scan\Cron\UploadScan::execute()} from
 * `etc/crontab.xml` under the `ironcart_scan` cron group.
 */
class UploadScan
{
    /**
     * Admin config path: continuous-monitoring cron enabled (Yes/No).
     * Default `0` (off) — see `etc/config.xml`. Hard invariant per
     * issue #64: must default off so a routine module update never
     * silently starts outbound HTTP traffic.
     */
    public const PATH_CRON_ENABLED = 'ironcart_scan/cron/enabled';

    /**
     * Admin config path: free-form crontab schedule expression. Default
     * `0 3 * * *`. Surfaced in admin so operators can move the run
     * window without editing XML — the value is wired via the
     * `<config_path>` element in `etc/crontab.xml`, which Magento's cron
     * framework consults at the start of each `cron:run
     * --group=ironcart_scan` invocation.
     */
    public const PATH_CRON_SCHEDULE = 'ironcart_scan/cron/schedule';

    /**
     * The cron group declared in `etc/crontab.xml`. Operators wanting to
     * trigger an out-of-band run can `bin/magento cron:run
     * --group=ironcart_scan`. The group name appears in the
     * `cron_schedule` table.
     */
    public const CRON_GROUP = 'ironcart_scan';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ScanEngineRunner $scanEngineRunner,
        private readonly UploadRunner $uploadRunner,
        private readonly LoggerInterface $logger,
        private readonly ?UpgradeNagEmitter $upgradeNagEmitter = null
    ) {
    }

    /**
     * Magento cron entry point. Bound from `etc/crontab.xml`.
     *
     * Returns `void` because the cron framework checks for thrown
     * exceptions, not return values, when deciding whether the job ran
     * successfully. A throwable here marks the matching `cron_schedule`
     * row as `error` and surfaces via the operator's standard cron
     * monitoring (CLI tail of `var/log/cron.log`, alerting on `error`
     * rows, etc.).
     *
     * @throws RuntimeException When the upload pipeline returns a non-OK
     *                          exit code, so the cron framework treats
     *                          the run as failed.
     */
    public function execute(): void
    {
        // Gate 1 — opt-in default OFF. Mirrors the UploadRunner gate but
        // checked earlier so a disabled cron exits without even loading
        // the check registry (which would otherwise scan the entire
        // Magento install on every cron tick).
        if (!$this->scopeConfig->isSetFlag(self::PATH_CRON_ENABLED)) {
            return;
        }

        $this->logger->info(
            'IronCart_Scan: cron upload run starting (continuous monitoring).'
        );

        try {
            $findings = $this->scanEngineRunner->runAndReport()->findings;
        } catch (Throwable $e) {
            $this->logger->error(
                'IronCart_Scan: cron upload run aborted — scan failed before upload',
                ['exception' => $e]
            );
            throw new RuntimeException(
                'IronCart_Scan cron: scan failed before upload — ' . $e->getMessage(),
                0,
                $e
            );
        }

        $outcome = $this->uploadRunner->run($findings);

        $this->handleOutcome($outcome);
    }

    /**
     * Translate an upload-runner outcome into log lines + an optional
     * throwable for the cron framework.
     *
     * Splitting this out makes the unit tests trivial — feed an
     * outcome, assert on the logger calls and on whether the runtime
     * exception bubbled up.
     */
    private function handleOutcome(UploadRunnerOutcome $outcome): void
    {
        switch ($outcome->exitCode) {
            case UploadRunnerOutcome::EXIT_OK:
                if ($outcome->viewUrl !== null && $outcome->viewUrl !== '') {
                    $this->logger->info(
                        'IronCart_Scan: cron upload succeeded',
                        ['view_url' => $outcome->viewUrl]
                    );
                } else {
                    $this->logger->info(
                        'IronCart_Scan: cron upload succeeded (no view_url returned).'
                    );
                }
                // #104 — surface the free-tier Pro upgrade nag in the
                // adminhtml notification dropdown after a successful
                // unlicensed upload. Cron is the canonical admin-
                // triggered upload path (the "Run scan now" button
                // only enqueues into the DB queue and never uploads),
                // so this is where the AC's "Magento admin notice"
                // requirement is satisfied. Suppressed entirely when
                // a license blob is configured.
                $this->upgradeNagEmitter?->pushAdminNotice();
                return;

            case UploadRunnerOutcome::EXIT_QUOTA_EXCEEDED:
                // 402 — free-tier exhausted. Log the server-provided
                // `upgrade_url` (validated https:// upstream in the
                // CurlUploadClient) and re-raise so the cron schedule
                // row goes red and the operator notices via their
                // standard cron-failure surface.
                $this->logger->warning(
                    'IronCart_Scan: cron upload blocked — upgrade required',
                    [
                        'upgrade_url' => $outcome->upgradeUrl
                            ?? 'https://ironcart.dev/pricing',
                        'category' => 'quota_exceeded',
                    ]
                );
                throw new RuntimeException($outcome->stderr);

            case UploadRunnerOutcome::EXIT_MISCONFIGURED:
            case UploadRunnerOutcome::EXIT_TRANSPORT:
            case UploadRunnerOutcome::EXIT_SERVER:
            default:
                // Every other non-OK outcome flows through the same
                // log-then-throw branch. The runner's stderr message
                // is the stable categorical label (never the raw
                // response body), so it is safe to log + throw verbatim.
                $this->logger->error(
                    'IronCart_Scan: cron upload failed',
                    [
                        'exit_code' => $outcome->exitCode,
                        'message' => $outcome->stderr,
                    ]
                );
                throw new RuntimeException($outcome->stderr);
        }
    }
}
