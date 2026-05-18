<?php

/**
 * IronCart_Scan — scan command.
 *
 * Implements `bin/magento ironcart:scan`, the entry point for the read-only
 * Magento security scanner. v0 wires the {@see CheckRegistry} so every
 * registered {@see \IronCart\Scan\Check\CheckInterface} contributes findings
 * into the canonical v0 JSON report — see
 * {@link https://github.com/IronCartLabs/IronCartM2/issues/2}.
 *
 * The command is read-only by default. v2 added the IC-080..IC-085 CSP
 * posture pack, which issues one HEAD request to the merchant's own
 * storefront base URL per scan, gated by a loopback / RFC1918 /
 * configured-base-URL allow-list. v3 adds the optional, opt-in `--upload`
 * flag which POSTs the scan output to `ironcart.dev/api/scan/ingest` — see
 * {@link https://github.com/IronCartLabs/IronCartM2/issues/57}.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Console\Command;

use IronCart\Scan\Check\CheckRegistry;
use IronCart\Scan\Check\License\UpgradeNagEmitter;
use IronCart\Scan\Check\ScanSession;
use IronCart\Scan\Check\Upload\UploadRunner;
use IronCart\Scan\Check\Upload\UploadRunnerOutcome;
use IronCart\Scan\Report\ReportBuilder;
use IronCart\Scan\Report\ReportRenderer;
use Magento\Framework\App\ProductMetadataInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `bin/magento ironcart:scan` — run the Ironcart read-only security scan.
 */
class ScanCommand extends Command
{
    public const COMMAND_NAME = 'ironcart:scan';

    public const OPTION_FORMAT = 'format';
    public const OPTION_OUTPUT = 'output';
    public const OPTION_INCLUDE_USERNAMES = 'include-usernames';
    public const OPTION_UPLOAD = 'upload';
    // v6 (#123) — multi-store agency overrides. Both options accept a
    // value and are NEVER persisted to `core_config_data`; they only
    // mutate the in-process {@see ScanSession} for the duration of the
    // current run. Intended for one-shot CI / cron-driven runs where
    // admin UI paste is impractical.
    public const OPTION_LICENSE = 'license';
    public const OPTION_UPLOAD_TOKEN = 'upload-token';

    public const FORMAT_JSON = 'json';
    public const FORMAT_TEXT = 'text';

    /**
     * Exit code returned when the scan completes (regardless of findings).
     */
    public const EXIT_OK = 0;

    /**
     * Exit code returned when the scan itself fails to run.
     */
    public const EXIT_FAILURE = 1;

    public function __construct(
        private readonly ProductMetadataInterface $productMetadata,
        private readonly ReportBuilder $reportBuilder,
        private readonly ReportRenderer $reportRenderer,
        private readonly CheckRegistry $checkRegistry,
        private readonly ScanSession $session,
        private readonly UploadRunner $uploadRunner,
        private readonly ?UpgradeNagEmitter $upgradeNagEmitter = null,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        // Symfony Console's setDescription / addOption description fields
        // accept plain strings, not Magento Phrase objects — Phrase has no
        // `__toString()` exposure that round-trips cleanly through the
        // console help renderer. We therefore cast the Magento `__()`
        // Phrase to a string at the call site so the translated text lands
        // on the right surface (CLI help) without breaking the framework
        // contract. The CSV row is the bare source string.
        $this->setName(self::COMMAND_NAME)
            ->setDescription((string) __('Run the Ironcart read-only Magento security scan.'))
            ->addOption(
                self::OPTION_FORMAT,
                'f',
                InputOption::VALUE_REQUIRED,
                (string) __('Output format: json|text'),
                self::FORMAT_JSON
            )
            ->addOption(
                self::OPTION_OUTPUT,
                'o',
                InputOption::VALUE_REQUIRED,
                (string) __('Write report to this file path instead of stdout'),
                null
            )
            ->addOption(
                self::OPTION_INCLUDE_USERNAMES,
                null,
                InputOption::VALUE_NONE,
                // Single-string literal (no `.` concatenation): the i18n
                // phrase collectors — both `bin/magento i18n:collect-phrases`
                // and our build-time `bin/check-i18n.php` — pull only the
                // first `T_CONSTANT_ENCAPSED_STRING` token after `__(`, so a
                // `'foo' . 'bar'` expression would extract just `'foo'` and
                // silently lose the rest of the phrase.
                (string) __('Include admin usernames in finding evidence. Off by default — usernames are PII under the IronCartM2 v0 policy and must be explicitly opted into per-run.')
            )
            ->addOption(
                self::OPTION_UPLOAD,
                null,
                InputOption::VALUE_NONE,
                (string) __('After the scan completes, upload the report to ironcart.dev for hosted viewing. Requires Stores → Configuration → Ironcart → Scan Upload → Enable, plus a token. Off by default. See docs/UPLOAD.md.')
            )
            ->addOption(
                self::OPTION_LICENSE,
                null,
                InputOption::VALUE_REQUIRED,
                (string) __('One-shot Pro license blob override for this run. Takes precedence over IRONCART_SCAN_LICENSE_BLOB and admin config. Never persisted. Useful for CI / cron-driven runs.'),
                null
            )
            ->addOption(
                self::OPTION_UPLOAD_TOKEN,
                null,
                InputOption::VALUE_REQUIRED,
                (string) __('One-shot upload token override for this run. Takes precedence over IRONCART_SCAN_UPLOAD_TOKEN and admin config. Never persisted. Pair with --upload.'),
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $format = (string) $input->getOption(self::OPTION_FORMAT);
            if (!in_array($format, [self::FORMAT_JSON, self::FORMAT_TEXT], true)) {
                // Wrap the entire sprintf template (not its result) so
                // translators reorder %1 freely; the placeholder is the
                // user-supplied --format value, kept verbatim. The
                // surrounding <error> tag is a Symfony Console formatter
                // directive, not user-visible content.
                $output->writeln(sprintf(
                    '<error>%s</error>',
                    (string) __('Unsupported --format value "%1". Use "json" or "text".', $format)
                ));

                return self::EXIT_FAILURE;
            }

            $this->session->setIncludeUsernames(
                (bool) $input->getOption(self::OPTION_INCLUDE_USERNAMES)
            );

            // v6 (#123) — push CLI overrides into the session BEFORE any
            // check runs. The session is wired `shared="true"` so the
            // LicenseConfig / UploadConfig instances injected into the
            // payload builder and upload runner will see them.
            $licenseOverride = $input->getOption(self::OPTION_LICENSE);
            $this->session->setLicenseOverride(
                is_string($licenseOverride) ? $licenseOverride : null
            );
            $uploadTokenOverride = $input->getOption(self::OPTION_UPLOAD_TOKEN);
            $this->session->setUploadTokenOverride(
                is_string($uploadTokenOverride) ? $uploadTokenOverride : null
            );

            $findings = $this->checkRegistry->runAll();

            $report = $this->reportBuilder->build(
                magentoVersion: $this->productMetadata->getVersion(),
                magentoEdition: $this->productMetadata->getEdition(),
                findings: $findings
            );

            $rendered = $this->reportRenderer->render($report, $format, $output);

            $outputPath = $input->getOption(self::OPTION_OUTPUT);
            if (is_string($outputPath) && $outputPath !== '') {
                $this->writeToFile($outputPath, $rendered);
                $output->writeln(sprintf(
                    '<info>%s</info>',
                    (string) __('Report written to %1', $outputPath)
                ));
            } elseif ($format === self::FORMAT_JSON) {
                // Text format already streamed directly to the console.
                $output->writeln($rendered);
            }

            // --upload runs AFTER the scan results have already been rendered.
            // The scan results are still emitted normally on stdout regardless
            // of whether the upload succeeds — operators wanting "scan only"
            // simply omit the flag.
            if ((bool) $input->getOption(self::OPTION_UPLOAD)) {
                return $this->handleUpload($findings, $output);
            }

            return self::EXIT_OK;
        } catch (Throwable $e) {
            // The exception message is kept verbatim — those originate
            // from PHP / Magento internals and stay English so
            // operators can grep them across log aggregators. Only the
            // wrapping label is translated.
            $output->writeln(sprintf(
                '<error>%s %s</error>',
                (string) __('Ironcart scan failed:'),
                $e->getMessage()
            ));

            return self::EXIT_FAILURE;
        }
    }

    /**
     * Drive the upload flow and translate the {@see UploadRunner} outcome
     * into a CLI exit code + stdout/stderr writes. The runner already
     * encapsulates the policy (opt-in gate, token check, payload size,
     * no-PII assertion); this method only does the I/O.
     */
    private function handleUpload(array $findings, OutputInterface $output): int
    {
        $outcome = $this->uploadRunner->run($findings);

        if ($outcome->stdout !== '') {
            $output->writeln($outcome->stdout);
        }
        if ($outcome->stderr !== '') {
            // Symfony Console's `getErrorOutput()` is the right channel for
            // non-zero-exit messages — they go to STDERR so wrapping
            // shell scripts can distinguish them from the JSON report.
            $errorOutput = method_exists($output, 'getErrorOutput')
                ? $output->getErrorOutput()
                : $output;
            $errorOutput->writeln('<error>' . $outcome->stderr . '</error>');
        }

        if ($outcome->exitCode === UploadRunnerOutcome::EXIT_OK) {
            // #104 — free-tier Pro upgrade nag. Only the success path
            // gets the nag; the 402 (`EXIT_QUOTA_EXCEEDED`) free-cap-
            // reached path already prints its own upgrade message via
            // the runner's stderr and would otherwise double-nag the
            // operator. Suppressed entirely when a license blob is
            // configured (regardless of whether it verifies — see
            // {@see UpgradeNagEmitter} class docblock).
            $nag = $this->upgradeNagEmitter?->cliMessage();
            if ($nag !== null) {
                $output->writeln($nag);
            }

            return self::EXIT_OK;
        }

        // EXIT_MISCONFIGURED / EXIT_TRANSPORT / EXIT_SERVER /
        // EXIT_QUOTA_EXCEEDED all map to CLI failure — cron picks them
        // up the same way.
        return $outcome->exitCode;
    }

    /**
     * Persist the rendered report to disk, creating parent directories as needed.
     *
     * @throws \RuntimeException When the target path is not writable.
     */
    private function writeToFile(string $path, string $contents): void
    {
        $directory = dirname($path);
        if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
            if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Unable to create directory "%s".', $directory));
            }
        }

        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException(sprintf('Unable to write report to "%s".', $path));
        }
    }
}
