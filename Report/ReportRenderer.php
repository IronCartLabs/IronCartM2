<?php

/**
 * IronCart_Scan — report renderer.
 *
 * Serialises a report array (produced by {@see ReportBuilder}) into either
 * pretty-printed JSON or a human-readable, severity-coloured text summary.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Report;

use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Render a v0 report as JSON or as a coloured text summary.
 */
class ReportRenderer
{
    /**
     * Map severity → Symfony Console style tag used for the text renderer.
     *
     * @var array<string,string>
     */
    private const SEVERITY_TAGS = [
        Severity::CRITICAL => 'fg=red;options=bold',
        Severity::HIGH => 'fg=red',
        Severity::MEDIUM => 'fg=yellow',
        Severity::LOW => 'fg=cyan',
        Severity::INFO => 'fg=default',
    ];

    /**
     * Render the report.
     *
     * For `text` format the renderer writes directly to `$output` (so colour
     * codes are honoured) and returns a plain-text copy with tags stripped,
     * suitable for `--output=<path>`. For `json` the rendered string is
     * returned untouched and the caller is responsible for emitting it.
     *
     * @param array{
     *     schema_version:string,
     *     generated_at:string,
     *     magento:array{version:string,edition:string},
     *     summary:array<string,int>,
     *     findings:list<array<string,mixed>>
     * } $report
     */
    public function render(array $report, string $format, OutputInterface $output): string
    {
        return match ($format) {
            'json' => $this->renderJson($report),
            'text' => $this->renderText($report, $output),
            default => throw new InvalidArgumentException(sprintf('Unsupported format "%s".', $format)),
        };
    }

    private function renderJson(array $report): string
    {
        $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode scan report as JSON: ' . json_last_error_msg());
        }

        return $encoded;
    }

    /**
     * @param array{
     *     schema_version:string,
     *     generated_at:string,
     *     magento:array{version:string,edition:string},
     *     summary:array<string,int>,
     *     findings:list<array<string,mixed>>
     * } $report
     */
    private function renderText(array $report, OutputInterface $output): string
    {
        $lines = [];

        $header = sprintf(
            'Ironcart scan — Magento %s (%s)',
            $report['magento']['version'] ?? 'unknown',
            $report['magento']['edition'] ?? 'unknown'
        );
        $lines[] = $header;
        $lines[] = str_repeat('-', strlen($header));
        $lines[] = sprintf('Generated at: %s', $report['generated_at'] ?? 'unknown');
        $lines[] = sprintf('Schema version: %s', $report['schema_version'] ?? 'unknown');
        $lines[] = '';
        $lines[] = 'Summary:';

        foreach (Severity::ALL as $severity) {
            $count = (int) ($report['summary'][$severity] ?? 0);
            $tag = self::SEVERITY_TAGS[$severity] ?? 'fg=default';
            $lines[] = sprintf('  <%s>%-8s</> %d', $tag, $severity, $count);
        }

        $findings = $report['findings'] ?? [];
        $lines[] = '';
        if ($findings === []) {
            $lines[] = '<info>No findings.</info>';
        } else {
            $lines[] = 'Findings:';
            $grouped = $this->groupBySeverity($findings);
            foreach (Severity::ALL as $severity) {
                if (empty($grouped[$severity])) {
                    continue;
                }
                $tag = self::SEVERITY_TAGS[$severity] ?? 'fg=default';
                $lines[] = sprintf('  <%s>[%s]</>', $tag, strtoupper($severity));
                foreach ($grouped[$severity] as $finding) {
                    $lines[] = sprintf(
                        '    - %s (%s)',
                        (string) ($finding['title'] ?? 'untitled'),
                        (string) ($finding['id'] ?? 'no-id')
                    );
                    if (!empty($finding['remediation_url'])) {
                        $lines[] = sprintf('      see: %s', (string) $finding['remediation_url']);
                    }
                }
            }
        }

        $rendered = implode("\n", $lines);
        $output->writeln($rendered);

        return $this->stripTags($rendered) . "\n";
    }

    /**
     * @param list<array<string,mixed>> $findings
     *
     * @return array<string,list<array<string,mixed>>>
     */
    private function groupBySeverity(array $findings): array
    {
        $grouped = [];
        foreach (Severity::ALL as $severity) {
            $grouped[$severity] = [];
        }

        foreach ($findings as $finding) {
            $severity = $finding['severity'] ?? null;
            if (is_string($severity) && Severity::isValid($severity)) {
                $grouped[$severity][] = $finding;
            }
        }

        return $grouped;
    }

    /**
     * Strip Symfony Console formatter tags for the file-output copy.
     */
    private function stripTags(string $text): string
    {
        return (string) preg_replace('#</?[a-zA-Z0-9=;,\-]+>#', '', $text);
    }
}
