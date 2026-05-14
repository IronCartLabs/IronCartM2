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
 * No outbound network calls are made by this command (v0 invariant).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Console\Command;

use IronCart\Scan\Check\CheckRegistry;
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

            return self::EXIT_OK;
        } catch (Throwable $e) {
            $output->writeln('<error>Ironcart scan failed: ' . $e->getMessage() . '</error>');

            return self::EXIT_FAILURE;
        }
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
