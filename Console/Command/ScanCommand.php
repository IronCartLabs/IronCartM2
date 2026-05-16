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
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Run the Ironcart read-only Magento security scan.')
            ->addOption(
                self::OPTION_FORMAT,
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format: json|text',
                self::FORMAT_JSON
            )
            ->addOption(
                self::OPTION_OUTPUT,
                'o',
                InputOption::VALUE_REQUIRED,
                'Write report to this file path instead of stdout',
                null
            )
            ->addOption(
                self::OPTION_INCLUDE_USERNAMES,
                null,
                InputOption::VALUE_NONE,
                'Include admin usernames in finding evidence. '
                . 'Off by default — usernames are PII under the IronCartM2 v0 policy '
                . 'and must be explicitly opted into per-run.'
            )
            ->addOption(
                self::OPTION_UPLOAD,
                null,
                InputOption::VALUE_NONE,
                'After the scan completes, upload the report to ironcart.dev for hosted viewing. '
                . 'Requires Stores → Configuration → Ironcart → Scan Upload → Enable, plus a token. '
                . 'Off by default. See docs/UPLOAD.md.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $format = (string) $input->getOption(self::OPTION_FORMAT);
            if (!in_array($format, [self::FORMAT_JSON, self::FORMAT_TEXT], true)) {
                $output->writeln(sprintf(
                    '<error>Unsupported --format value "%s". Use "json" or "text".</error>',
                    $format
                ));

                return self::EXIT_FAILURE;
            }

            $this->session->setIncludeUsernames(
                (bool) $input->getOption(self::OPTION_INCLUDE_USERNAMES)
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
                $output->writeln(sprintf('<info>Report written to %s</info>', $outputPath));
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
            $output->writeln('<error>Ironcart scan failed: ' . $e->getMessage() . '</error>');

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

        return match ($outcome->exitCode) {
            UploadRunnerOutcome::EXIT_OK => self::EXIT_OK,
            // EXIT_MISCONFIGURED / EXIT_TRANSPORT / EXIT_SERVER all map to
            // CLI failure — cron picks them up the same way.
            default => $outcome->exitCode,
        };
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
