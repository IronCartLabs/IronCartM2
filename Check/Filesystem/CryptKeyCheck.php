<?php

/**
 * IronCart_Scan — IC-032: crypt key presence + non-placeholder.
 *
 * Magento's encryption key lives at `app/etc/env.php` under
 * `crypt.key` (newline-separated history; the last entry is active). A
 * missing or placeholder value means stored credentials and 2FA secrets are
 * effectively unprotected.
 *
 * Privacy invariant: this check MUST NOT read the key value into the report
 * payload. Only presence, format, and a known-placeholder match are emitted.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Filesystem;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Report\Severity;

/**
 * IC-032: ensure the Magento crypt key is set and not the documented placeholder.
 */
class CryptKeyCheck implements CheckInterface
{
    public const ID = 'IC-032';

    private const REMEDIATION_URL =
        'https://experienceleague.adobe.com/docs/commerce-operations/installation-guide/next-steps/encryption-key.html';

    /**
     * Placeholder strings that appear in Adobe / community documentation
     * snippets — finding any of these in `env.php` means the operator
     * never rotated the key.
     *
     * @var list<string>
     */
    private const PLACEHOLDERS = [
        'CHANGEME',
        'CHANGE_ME',
        'YOUR_KEY_HERE',
        'your-key-here',
        '0000000000000000000000000000000000000000000000000000000000000000',
    ];

    public function __construct(private readonly MagentoRoot $root)
    {
    }

    /**
     * @inheritDoc
     */
    public function run(): array
    {
        $path = $this->root->envPhp();

        if (!is_file($path) || !is_readable($path)) {
            return [[
                'id' => self::ID,
                'title' => 'Crypt key could not be inspected (env.php missing or unreadable)',
                'severity' => Severity::INFO,
                'evidence' => ['path' => $path],
                'remediation_url' => self::REMEDIATION_URL,
            ]];
        }

        try {
            /** @var mixed $config */
            $config = include $path;
        } catch (\Throwable $e) {
            return [[
                'id' => self::ID,
                'title' => 'Crypt key could not be inspected (env.php failed to load)',
                'severity' => Severity::INFO,
                'evidence' => ['path' => $path, 'error' => $e->getMessage()],
                'remediation_url' => self::REMEDIATION_URL,
            ]];
        }

        if (!is_array($config)) {
            return [[
                'id' => self::ID,
                'title' => 'Crypt key could not be inspected (env.php did not return an array)',
                'severity' => Severity::INFO,
                'evidence' => ['path' => $path],
                'remediation_url' => self::REMEDIATION_URL,
            ]];
        }

        $key = $config['crypt']['key'] ?? null;

        if (!is_string($key) || $key === '') {
            return [[
                'id' => self::ID,
                'title' => 'Magento crypt key is missing',
                'severity' => Severity::HIGH,
                // Do NOT include the key value, even when absent — keep the
                // evidence shape symmetric so consumers can diff reports.
                'evidence' => ['path' => $path, 'present' => false],
                'remediation_url' => self::REMEDIATION_URL,
            ]];
        }

        // The crypt.key history is newline-separated. The active key is the last entry.
        $entries = preg_split('/\R/', $key) ?: [];
        $entries = array_values(array_filter(array_map('trim', $entries), static fn ($v): bool => $v !== ''));

        if ($entries === []) {
            return [[
                'id' => self::ID,
                'title' => 'Magento crypt key is empty',
                'severity' => Severity::HIGH,
                'evidence' => ['path' => $path, 'present' => false],
                'remediation_url' => self::REMEDIATION_URL,
            ]];
        }

        $active = end($entries);

        if ($this->isPlaceholder($active)) {
            return [[
                'id' => self::ID,
                'title' => 'Magento crypt key is set to a documentation placeholder',
                'severity' => Severity::HIGH,
                // Evidence records presence + structural facts only — never the key bytes.
                'evidence' => [
                    'path' => $path,
                    'present' => true,
                    'history_entries' => count($entries),
                    'placeholder_match' => true,
                ],
                'remediation_url' => self::REMEDIATION_URL,
            ]];
        }

        // Healthy key — no finding emitted, but record schema-friendly evidence
        // for completeness when callers later opt in to an `info` baseline. v0
        // keeps this silent.
        return [];
    }

    private function isPlaceholder(string $candidate): bool
    {
        $needle = strtolower($candidate);
        foreach (self::PLACEHOLDERS as $placeholder) {
            if ($needle === strtolower($placeholder)) {
                return true;
            }
        }

        return false;
    }
}
