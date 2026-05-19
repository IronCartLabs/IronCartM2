<?php

/**
 * IronCart_Scan — Recon 7.1 rebaseline command.
 *
 * Implements `bin/magento recon:integrity:rebaseline`: rebuilds the local
 * file-integrity baseline from a fresh walk of `app/code/**`, `app/etc/**`,
 * and `vendor/magento/**`, then persists it to `var/recon/baseline.json`.
 *
 * Run this whenever legitimate code changes ship — composer upgrades,
 * module installs, hot-patches. Without a fresh baseline {@see FileHashCheck}
 * will flag the legitimate diff as tampering. The command is ACL-gated via
 * the dedicated `IronCart_Scan::recon_integrity_rebaseline` resource so
 * non-admin operators cannot reset the baseline (and thereby silence the
 * check) via shell access.
 *
 * Pro-tier — refuses to run when no verified license claim is present, so a
 * compromised free-tier install can't legitimise tampering by rebaselining.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Console\Command;

use IronCart\Scan\Check\Integrity\BaselineBuilder;
use IronCart\Scan\Check\Integrity\BaselineRepository;
use IronCart\Scan\Check\License\LicenseConfig;
use Magento\Framework\App\ProductMetadataInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ReconIntegrityRebaselineCommand extends Command
{
    public const COMMAND_NAME = 'recon:integrity:rebaseline';

    public const OPTION_FORCE = 'force';

    public const EXIT_OK = 0;
    public const EXIT_FAILURE = 1;
    public const EXIT_NO_LICENSE = 2;

    public function __construct(
        private readonly ProductMetadataInterface $productMetadata,
        private readonly BaselineBuilder $baselineBuilder,
        private readonly BaselineRepository $baselineRepository,
        private readonly LicenseConfig $licenseConfig,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription((string) __('Rebuild the Recon file-integrity baseline from the current webroot. Pro only.'))
            ->addOption(
                self::OPTION_FORCE,
                null,
                InputOption::VALUE_NONE,
                (string) __('Overwrite an existing baseline without prompting (always implied in non-interactive mode).')
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $claims = $this->licenseConfig->parsedClaims();
            if ($claims === null) {
                $output->writeln(sprintf(
                    '<error>%s</error>',
                    (string) __('Recon file-integrity requires a verified Pro license. Configure ironcart_scan/license/blob and rerun.')
                ));
                return self::EXIT_NO_LICENSE;
            }

            $force = (bool) $input->getOption(self::OPTION_FORCE);
            if ($this->baselineRepository->exists() && !$force && $input->isInteractive()) {
                $output->writeln(sprintf(
                    '<comment>%s</comment>',
                    (string) __('A baseline already exists at %1; pass --force to overwrite.', $this->baselineRepository->path())
                ));
                return self::EXIT_FAILURE;
            }

            $output->writeln(sprintf(
                '<info>%s</info>',
                (string) __('Building Recon file-integrity baseline; this can take a couple of minutes on a typical webroot.')
            ));

            $manifest = $this->baselineBuilder->build(
                magentoEdition: (string) $this->productMetadata->getEdition(),
                magentoVersion: (string) $this->productMetadata->getVersion()
            );

            $this->baselineRepository->save($manifest);

            $output->writeln(sprintf(
                '<info>%s</info>',
                (string) __(
                    'Wrote %1 baseline entries to %2.',
                    (string) $manifest->count(),
                    $this->baselineRepository->path()
                )
            ));

            return self::EXIT_OK;
        } catch (Throwable $e) {
            $output->writeln(sprintf(
                '<error>%s %s</error>',
                (string) __('Recon rebaseline failed:'),
                $e->getMessage()
            ));
            return self::EXIT_FAILURE;
        }
    }
}
