<?php

/**
 * IronCart_Scan — IC-001 Magento version / patch-level check.
 *
 * Reports the running Magento version, edition (Open Source vs. Adobe
 * Commerce), and the most recent patch release we recognise on the same
 * minor line. Severity is derived from how many days behind the running
 * version is, per the rules in
 * {@link https://github.com/IronCartLabs/IronCartM2/issues/3}:
 *
 *   - `critical` if the latest known patch is > 90 days newer
 *   - `high`     if 30–90 days newer
 *   - `medium`   if 0–30 days newer
 *   - `info`     if the running version matches the latest known patch
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\PatchLevel;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\ProductMetadataInterface;

/**
 * IC-001 — Magento version, edition, and patch-level freshness.
 */
class MagentoVersionCheck implements CheckInterface
{
    public const ID = 'IC-001';

    public const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-001';

    public function __construct(
        private readonly ProductMetadataInterface $productMetadata,
        private readonly ?DateTimeImmutable $now = null
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function title(): string
    {
        return 'Magento version and patch level';
    }

    public function run(): array
    {
        $version = (string) $this->productMetadata->getVersion();
        $edition = (string) $this->productMetadata->getEdition();

        $latest = MagentoPatchCatalog::latestInMinorLine($version);
        $runningReleased = MagentoPatchCatalog::releaseDate($version);
        $latestReleased = $latest !== null ? MagentoPatchCatalog::releaseDate($latest) : null;

        $now = $this->now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $daysBehind = self::daysBehind($runningReleased, $latestReleased, $now);
        $severity = self::gradeSeverity($version, $latest, $daysBehind);

        $evidence = [
            'version' => $version,
            'edition' => $edition,
            'latest_known_in_line' => $latest,
            'latest_known_in_line_released' => $latestReleased?->format('Y-m-d'),
            'running_version_released' => $runningReleased?->format('Y-m-d'),
            'days_behind_latest' => $daysBehind,
            'catalog_known' => $runningReleased !== null,
        ];

        return [
            [
                'id' => self::ID,
                'title' => $this->title(),
                'severity' => $severity,
                'evidence' => $evidence,
                'remediation_url' => self::REMEDIATION_URL,
            ],
        ];
    }

    /**
     * Compute days between `$running` and `$latest`, clamped to >= 0.
     *
     * Returns null when either date is missing (running version not in
     * the catalogue, or no known peers on the same minor line).
     */
    private static function daysBehind(
        ?DateTimeInterface $running,
        ?DateTimeInterface $latest,
        DateTimeInterface $now
    ): ?int {
        if ($latest === null) {
            return null;
        }

        // If we don't know when the running build shipped, fall back to
        // measuring how stale the *latest* release is relative to "now".
        // That still surfaces an out-of-date minor line as old.
        $reference = $running ?? $now;

        $diff = $latest->getTimestamp() - $reference->getTimestamp();
        if ($diff <= 0) {
            return 0;
        }

        return (int) floor($diff / 86400);
    }

    /**
     * Map "days behind latest" → severity per issue #3.
     *
     * Special case: when the running version exactly matches the latest
     * known patch on the line, we emit `info` (recorded for the report
     * even though it isn't a vulnerability).
     */
    private static function gradeSeverity(string $version, ?string $latest, ?int $daysBehind): string
    {
        if ($latest === null || $daysBehind === null) {
            // Unknown future build or pre-catalogue release — surface as
            // info so the operator still sees the version line.
            return Severity::INFO;
        }

        if (version_compare($version, $latest, '>=')) {
            return Severity::INFO;
        }

        if ($daysBehind > 90) {
            return Severity::CRITICAL;
        }

        if ($daysBehind >= 30) {
            return Severity::HIGH;
        }

        return Severity::MEDIUM;
    }
}
