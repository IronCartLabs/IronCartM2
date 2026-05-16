<?php

/**
 * IronCart_Scan — IC-060 OSV.dev cross-reference via the ironcart.dev proxy.
 *
 * Collects the installed package list from `composer.lock`, POSTs it to
 * `https://ironcart.dev/api/cve` (the proxied branch of the v2 OSV
 * decision — see `.claude/memory/project_open_decisions.md`, locked
 * 2026-05-16), and emits one finding per package with a known advisory.
 *
 * Module side stays read-only-from-store-perspective: outbound network
 * is gated behind a single explicit POST whose destination is checked
 * against an `ironcart.dev` allowlist *before* any socket is opened
 * ({@see CveProxyClient}). The merchant must opt in via the
 * `ironcart_scan/cve/enabled` admin config flag; default is OFF, and
 * when disabled this check returns ONE info-level finding telling the
 * operator where to flip the switch.
 *
 * Failure mode: any error (host-check refusal, curl transport, non-2xx,
 * JSON parse) emits ONE informational IC-061 finding ("OSV cross-
 * reference unavailable") and returns — the rest of the scan continues.
 *
 * Severity is computed from the CVSS v3 base score the proxy returns:
 *
 *   - CRITICAL when score >= 9.0
 *   - HIGH     when 7.0 <= score < 9.0
 *   - MEDIUM   when 4.0 <= score < 7.0
 *   - LOW      when 0   <= score < 4.0
 *
 * Payload contains zero PII. Package names and versions only — no
 * domain, admin username, IP, or store identifier. The proxy logs the
 * source IP from the HTTP request itself; nothing else is sent.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Cve;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\PatchLevel\ComposerLockReader;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\ModuleListInterface;
use Throwable;

/**
 * IC-060 — Composer package CVE cross-reference via ironcart.dev proxy.
 */
class ComposerCveCheck implements CheckInterface
{
    public const ID = 'IC-060';

    public const FALLBACK_ID = 'IC-061';

    /**
     * Admin config path for the opt-in flag. Defaults to `0`
     * (disabled) via `etc/config.xml`. Operators flip it in
     * Stores → Configuration → Ironcart → Scan → Enable CVE lookup.
     */
    public const CONFIG_ENABLED = 'ironcart_scan/cve/enabled';

    /**
     * Proxy endpoint. Hard-coded — the {@see CveProxyClient::ALLOWED_HOST}
     * allowlist already pins the host, but pinning the full URL here as
     * well means a configuration mistake can't even point at a different
     * path on the right host.
     */
    public const PROXY_URL = 'https://ironcart.dev/api/cve';

    public const REMEDIATION_URL_BASE = 'https://ironcart.dev/docs/checks/IC-060';

    private const FALLBACK_REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-061';

    private const SCHEMA_VERSION = '1';

    /**
     * Single-call cap. Magento 2.4 base install has ~250 composer
     * packages; merchants who add a handful of extensions still fit
     * comfortably here. Beyond this we batch.
     */
    public const BATCH_THRESHOLD = 500;

    /**
     * Chunk size for the batched path.
     */
    public const BATCH_SIZE = 200;

    private const MODULE_NAME = 'IronCart_Scan';

    /**
     * Severity thresholds keyed by lower-bound CVSS v3 base score.
     * Ordered worst-first.
     *
     * @var list<array{0: float, 1: string}>
     */
    private const SEVERITY_THRESHOLDS = [
        [9.0, Severity::CRITICAL],
        [7.0, Severity::HIGH],
        [4.0, Severity::MEDIUM],
        [0.0, Severity::LOW],
    ];

    public function __construct(
        private readonly ComposerLockReader $lockReader,
        private readonly CveProxyClient $proxyClient,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ModuleListInterface $moduleList
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(): array
    {
        if (!$this->isEnabled()) {
            return [[
                'id' => self::ID,
                'title' => 'OSV cross-reference disabled (opt-in)',
                'severity' => Severity::INFO,
                'evidence' => [
                    'status' => 'disabled',
                    'config_path' => self::CONFIG_ENABLED,
                    'enable_via' => 'Stores → Configuration → Ironcart → Scan → Enable CVE lookup',
                ],
                'remediation_url' => self::REMEDIATION_URL_BASE,
            ]];
        }

        try {
            $packages = $this->lockReader->packages();
        } catch (Throwable $e) {
            return [$this->fallbackFinding('composer.lock unavailable: ' . $e->getMessage())];
        }

        if ($packages === []) {
            return [];
        }

        $batches = $this->chunkPackages($packages);
        $userAgent = $this->userAgent();
        $findings = [];

        foreach ($batches as $batch) {
            $payload = [
                'schema_version' => self::SCHEMA_VERSION,
                'source' => sprintf('ironcart-magento-scan/%s', $this->moduleVersion()),
                'packages' => $batch,
            ];

            $response = $this->proxyClient->post(self::PROXY_URL, $payload, $userAgent);

            if ($response === null) {
                // Any single-batch failure is total — emit one IC-061
                // and stop calling out. We don't want a half-populated
                // report that looks complete but silently dropped the
                // remaining batches.
                return [$this->fallbackFinding('proxy request failed')];
            }

            $rawFindings = $response['findings'] ?? null;
            if (!is_array($rawFindings)) {
                return [$this->fallbackFinding('unexpected response shape (no findings array)')];
            }

            foreach ($rawFindings as $raw) {
                $finding = $this->normaliseFinding($raw);
                if ($finding !== null) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * Read the opt-in flag. Defaults to disabled — see `etc/config.xml`.
     */
    private function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_ENABLED);
    }

    /**
     * Slice the package map into POST-ready chunks.
     *
     * Stores at most 500 packages in a single request; beyond that we
     * batch in chunks of 200 so the request stays well below any
     * reasonable WAF body-size limit.
     *
     * @param array<string, string> $packages name → version map.
     *
     * @return list<list<array{name:string, version:string}>>
     */
    private function chunkPackages(array $packages): array
    {
        $flat = [];
        foreach ($packages as $name => $version) {
            $flat[] = ['name' => $name, 'version' => $version];
        }

        if (count($flat) <= self::BATCH_THRESHOLD) {
            return [$flat];
        }

        $chunks = array_chunk($flat, self::BATCH_SIZE);
        // array_chunk preserves keys on inner arrays but produces a
        // list of lists, which is exactly what we want. The outer
        // numeric reindex is implicit.
        return array_values($chunks);
    }

    /**
     * Coerce one proxy `findings[]` row into the canonical v0 finding
     * shape. Returns null if the row is missing required fields — we
     * silently drop malformed rows rather than failing the whole batch
     * because the proxy's contract may grow new fields over time.
     *
     * @param mixed $raw
     *
     * @return array{
     *     id:string,
     *     title:string,
     *     severity:string,
     *     evidence:array<string, mixed>,
     *     remediation_url:string
     * }|null
     */
    private function normaliseFinding(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $package = isset($raw['package']) && is_string($raw['package']) ? $raw['package'] : '';
        $version = isset($raw['version']) && is_string($raw['version']) ? $raw['version'] : '';
        $advisoryId = isset($raw['advisory_id']) && is_string($raw['advisory_id'])
            ? $raw['advisory_id']
            : '';

        if ($package === '' || $advisoryId === '') {
            return null;
        }

        $summary = isset($raw['summary']) && is_string($raw['summary']) ? $raw['summary'] : '';
        $cvssScore = self::numericOrNull($raw['cvss_score'] ?? null);
        $declared = isset($raw['severity']) && is_string($raw['severity'])
            ? strtolower(trim($raw['severity']))
            : '';

        $severity = $this->resolveSeverity($cvssScore, $declared);

        $remediationUrl = isset($raw['remediation_url']) && is_string($raw['remediation_url'])
            && $raw['remediation_url'] !== ''
            ? $raw['remediation_url']
            : self::REMEDIATION_URL_BASE . '#' . rawurlencode($advisoryId);

        return [
            'id' => self::ID,
            'title' => sprintf('%s — %s', $package, $advisoryId),
            'severity' => $severity,
            'evidence' => [
                'package' => $package,
                'installed_version' => $version,
                'advisory_id' => $advisoryId,
                'summary' => $summary,
                'cvss_score' => $cvssScore,
                'declared_severity' => $declared !== '' ? $declared : null,
            ],
            'remediation_url' => $remediationUrl,
        ];
    }

    /**
     * Pick a severity. Prefer the CVSS-derived bucket; fall back to the
     * proxy's declared string when the score is missing; final fallback
     * is MEDIUM so a malformed-but-present advisory is still surfaced.
     */
    private function resolveSeverity(?float $cvss, string $declared): string
    {
        if ($cvss !== null) {
            foreach (self::SEVERITY_THRESHOLDS as [$threshold, $sev]) {
                if ($cvss >= $threshold) {
                    return $sev;
                }
            }
            return Severity::LOW;
        }

        if ($declared !== '' && Severity::isValid($declared)) {
            return $declared;
        }

        return Severity::MEDIUM;
    }

    private static function numericOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        return null;
    }

    /**
     * Build the IC-061 informational finding shape.
     */
    private function fallbackFinding(string $reason): array
    {
        return [
            'id' => self::FALLBACK_ID,
            'title' => 'OSV cross-reference unavailable',
            'severity' => Severity::LOW,
            'evidence' => [
                'status' => 'unavailable',
                'reason' => $reason,
                'proxy_url' => self::PROXY_URL,
            ],
            'remediation_url' => self::FALLBACK_REMEDIATION_URL,
        ];
    }

    /**
     * UA string for the POST. Mirrors the CSP-probe pattern so server-
     * side logs at ironcart.dev can attribute the request to a specific
     * module version.
     */
    private function userAgent(): string
    {
        return sprintf(
            'IronCart-Scan/%s (cve-cross-reference)',
            $this->moduleVersion()
        );
    }

    /**
     * Resolve the installed module version from `etc/module.xml`.
     * Falls back to `0.0.0` if the manifest is unreadable — defensive,
     * should only happen if the module deploy is broken in which case
     * the rest of the scan would already be failing.
     */
    private function moduleVersion(): string
    {
        $module = $this->moduleList->getOne(self::MODULE_NAME);
        $version = is_array($module) && isset($module['setup_version'])
            ? (string) $module['setup_version']
            : '0.0.0';
        return $version !== '' ? $version : '0.0.0';
    }
}
