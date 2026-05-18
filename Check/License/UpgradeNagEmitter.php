<?php

/**
 * IronCart_Scan — free-tier upload Pro-upgrade nag emitter.
 *
 * Pure-policy class that decides whether a post-`--upload` upgrade hint
 * should fire, and (when called from a Magento admin-side context) routes
 * the same hint through `NotifierInterface` so it surfaces in the
 * adminhtml notification dropdown.
 *
 * Wired into two upload callers — see `etc/di.xml`:
 *
 *   - `bin/magento ironcart:scan --upload`
 *     ({@see \IronCart\Scan\Console\Command\ScanCommand::handleUpload()}) —
 *     emits a single CLI stdout line on `UploadRunnerOutcome::EXIT_OK`
 *     when no license blob is configured.
 *
 *   - `etc/crontab.xml` `ironcart_scan_upload_cron`
 *     ({@see \IronCart\Scan\Cron\UploadScan::handleOutcome()}) — adds a
 *     Magento admin notice on successful cron uploads when no license
 *     blob is configured. Cron is the canonical "admin-triggered upload"
 *     path: the admin "Run scan now" button only enqueues a scan run
 *     into the DB queue and never uploads.
 *
 * Suppression contract (per IronCartLabs/IronCartM2#104 AC):
 *
 *   - Empty / unset `ironcart_scan/license/blob`  → nag fires.
 *   - Non-empty blob (regardless of verification result) → nag suppressed.
 *     The dashboard surfaces invalid-license errors separately; we don't
 *     want to nudge an operator who already paid into pasting a fresh
 *     blob just because their existing one expired or is malformed.
 *   - Never fires on a non-success upload (the caller short-circuits
 *     before this emitter is invoked — see {@see ScanCommand}).
 *
 * Read-only invariant: opens no sockets, writes no files, performs no
 * DB writes beyond `NotifierInterface`'s own canonical adminhtml insert.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 *
 * @see https://github.com/IronCartLabs/IronCartM2/issues/104
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\License;

use Magento\Framework\Notification\NotifierInterface;
use Throwable;

/**
 * Emits the v5 "upgrade to Pro" nag after a successful free-tier upload.
 */
class UpgradeNagEmitter
{
    /**
     * Stable CLI line emitted after `--upload` succeeds on a free-tier
     * (unlicensed) module. The wording is part of the AC on #104 — do
     * NOT reword without bumping the AC.
     */
    public const CLI_MESSAGE = 'Upgrade to Pro for unlimited hosted reports, continuous monitoring, '
        . 'and notifications: https://ironcart.dev/pro';

    /**
     * Title shown in the adminhtml notification dropdown. Kept short so
     * Magento's UI doesn't truncate it; the longer body sits in the
     * description.
     */
    public const NOTICE_TITLE = 'Ironcart Scan — upgrade to Pro';

    /**
     * Body text + URL surfaced inside the adminhtml notification entry.
     * Mirrors {@see CLI_MESSAGE} verbatim so support requests reference
     * the same sentence regardless of how the operator first hit it.
     */
    public const NOTICE_DESCRIPTION = 'Upgrade to Pro for unlimited hosted reports, '
        . 'continuous monitoring, and notifications.';

    public const NOTICE_URL = 'https://ironcart.dev/pro';

    public function __construct(
        private readonly LicenseConfig $licenseConfig,
        private readonly NotifierInterface $notifier
    ) {
    }

    /**
     * Should the post-upload nag fire?
     *
     * Returns true only when no license blob is configured at all.
     * Suppresses on any non-empty blob (even an expired / malformed one
     * — see class docblock for rationale).
     */
    public function shouldEmit(): bool
    {
        return $this->licenseConfig->blob() === '';
    }

    /**
     * Return the CLI line to append after `--upload` succeeds, or null
     * when the nag is suppressed. The caller writes it to stdout itself
     * so this class stays free of Symfony Console knowledge.
     */
    public function cliMessage(): ?string
    {
        return $this->shouldEmit() ? self::CLI_MESSAGE : null;
    }

    /**
     * Push the nag into the Magento adminhtml notification dropdown.
     *
     * Safe to call from a cron context — `NotifierInterface` is a thin
     * resource-model wrapper that writes one row to
     * `admin_system_messages`. Failures inside the notifier are caught
     * here so they never bubble back up and fail an otherwise-successful
     * scan upload run.
     *
     * @return bool True if the notice was added; false if suppressed
     *              by the license check or swallowed by an internal
     *              notifier failure.
     */
    public function pushAdminNotice(): bool
    {
        if (!$this->shouldEmit()) {
            return false;
        }
        try {
            $this->notifier->addNotice(
                self::NOTICE_TITLE,
                self::NOTICE_DESCRIPTION,
                self::NOTICE_URL
            );
            return true;
        } catch (Throwable $e) {
            // Swallow — adminhtml notice plumbing failures must NEVER
            // turn a green upload into a red one. Operators still see
            // the CLI / log line; the dropdown nag is a bonus, not the
            // primary surface.
            return false;
        }
    }
}
