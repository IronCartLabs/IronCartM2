<?php

/**
 * IronCart_Scan — IC-003: Adobe hotfix VULN-39341 (CVE-2026-75650) presence.
 *
 * CVE-2026-75650 (APSB26-146, 2026-09-07) is an unauthenticated, CVSS 10.0
 * template-engine RCE that Adobe confirmed as exploited in the wild. Adobe
 * ships the fix as an isolated patch file (`VULN-39341`), not as a Composer
 * release, and APSB26-153 (the `2.4.x-2026-oct` releases) still tells
 * merchants to apply it separately. IC-001 / IC-002 therefore cannot tell a
 * patched store from an unpatched one; this check reads the patched files
 * directly.
 *
 * Signature derivation (IronCartLabs/IronCartM2#207): all ten published
 * VULN-39341 variants (2.4.4-p18 through 2.4.9) change the same files with
 * byte-identical added / removed lines, so the markers below are
 * independent of the Magento line. Only files where the hotfix REMOVES a
 * line are used, so both states are positively identified — a vulnerable
 * verdict is never inferred from a marker's absence. No Adobe patch content
 * is bundled beyond these single-line markers.
 *
 * Findings:
 *
 *   - `IC-003` CRITICAL — at least one signature file still carries the
 *                         pre-hotfix code
 *   - `IC-003` INFO     — no file is vulnerable, but at least one could not
 *                         be classified (missing, unreadable, customised, or
 *                         a non-`vendor/` install layout)
 *   - no finding         — every signature file carries the hotfix
 *
 * Read-only. Makes no outbound network calls. Does not (and cannot) verify
 * that the encryption key was rotated, which Adobe also requires.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\PatchLevel;

use IronCart\Scan\Check\CheckInterface;
use IronCart\Scan\Check\Filesystem\MagentoRoot;
use IronCart\Scan\Report\Severity;

/**
 * IC-003 — detect a missing Adobe VULN-39341 hotfix by file signature.
 */
class Cve202675650HotfixCheck implements CheckInterface
{
    public const ID = 'IC-003';

    public const REMEDIATION_URL = 'https://ironcart.dev/docs/checks/IC-003';

    public const STATE_PATCHED = 'patched';

    public const STATE_VULNERABLE = 'vulnerable';

    public const STATE_UNKNOWN = 'unknown';

    /**
     * Adobe KB article that links the hotfix downloads per Magento line.
     */
    private const HOTFIX_KB_URL =
        'https://experienceleague.adobe.com/en/docs/commerce-knowledge-base/kb/announcements/commerce-apsb26-146';

    /**
     * Upper bound on bytes read per file. The signature files are all well
     * under 50 KiB; anything larger is not a recognisable core file.
     */
    private const MAX_FILE_BYTES = 1048576;

    private const REMEDIATION_TEXT =
        'Apply Adobe hotfix VULN-39341 for CVE-2026-75650 (APSB26-146) for your Magento line, using the '
        . 'patch linked from ' . self::HOTFIX_KB_URL . ' . Re-apply it after any composer install / update '
        . 'that redeploys pub/errors/. Adobe also requires rotating the Magento encryption key after '
        . 'patching, and rotating credentials stored in Commerce at their source, because key rotation '
        . 'alone does not invalidate secrets that may already have been exposed. This check verifies the '
        . 'hotfix files only; it does NOT verify that the encryption key or credentials were rotated.';

    /**
     * Signature files, relative to the Magento root, with the single-line
     * marker the hotfix removes (`vulnerable`) and adds (`patched`).
     *
     * @var array<string,array{vulnerable:string,patched:string}>
     */
    private const SIGNATURES = [
        'vendor/magento/framework/View/Element/BlockFactory.php' => [
            'vulnerable' => 'if (!$block instanceof BlockInterface) {',
            'patched' => 'if (!is_a($resolvedType, BlockInterface::class, true)) {',
        ],
        'vendor/magento/module-backend/Model/Widget/Grid/Row/UrlGeneratorFactory.php' => [
            'vulnerable' => 'if (false === $rowUrlGenerator instanceof '
                . '\Magento\Backend\Model\Widget\Grid\Row\GeneratorInterface) {',
            'patched' => 'if (!is_a($generatorClassName, '
                . '\Magento\Backend\Model\Widget\Grid\Row\GeneratorInterface::class, true)) {',
        ],
        'vendor/magento/framework/Webapi/ErrorProcessor.php' => [
            'vulnerable' => '$this->directoryWrite->writeFile(\'report/api/\' . $reportId, '
                . '$this->serializer->serialize($reportData));',
            'patched' => 'self::REPORT_EXECUTION_GUARD . PHP_EOL . $this->serializer->serialize($reportData)',
        ],
        'pub/errors/processor.php' => [
            'vulnerable' => '@file_put_contents($this->_reportFile, '
                . '$this->serializer->serialize($reportData). PHP_EOL);',
            'patched' => 'self::REPORT_EXECUTION_GUARD . PHP_EOL . '
                . '$this->serializer->serialize($reportData) . PHP_EOL',
        ],
    ];

    public function __construct(private readonly MagentoRoot $root)
    {
    }

    /**
     * @inheritDoc
     */
    public function run(): array
    {
        $states = [];
        foreach (self::SIGNATURES as $relative => $markers) {
            $states[$relative] = $this->classify($this->root->join($relative), $markers);
        }

        if (in_array(self::STATE_VULNERABLE, $states, true)) {
            return [[
                'id' => self::ID,
                'title' => 'Adobe hotfix VULN-39341 for CVE-2026-75650 (APSB26-146, exploited in the wild) '
                    . 'is not applied',
                'severity' => Severity::CRITICAL,
                'evidence' => $this->evidence($states),
                'remediation_url' => self::REMEDIATION_URL,
            ]];
        }

        if (in_array(self::STATE_UNKNOWN, $states, true)) {
            $evidence = $this->evidence($states);
            $evidence['explanation'] = 'One or more hotfix signature files were missing, unreadable, or did '
                . 'not match the known pre- or post-hotfix code, so this store could not be classified. '
                . 'Expected layout is a Composer install with core under vendor/magento/. Verify manually '
                . 'that VULN-39341 is applied.';

            return [[
                'id' => self::ID,
                'title' => 'CVE-2026-75650 hotfix status could not be determined (unrecognised file layout)',
                'severity' => Severity::INFO,
                'evidence' => $evidence,
                'remediation_url' => self::REMEDIATION_URL,
            ]];
        }

        return [];
    }

    /**
     * Classify one signature file.
     *
     * @param array{vulnerable:string,patched:string} $markers
     */
    private function classify(string $absolute, array $markers): string
    {
        if (!is_file($absolute) || !is_readable($absolute)) {
            return self::STATE_UNKNOWN;
        }

        $source = @file_get_contents($absolute, false, null, 0, self::MAX_FILE_BYTES);
        if (!is_string($source) || $source === '') {
            return self::STATE_UNKNOWN;
        }

        $vulnerable = str_contains($source, $markers['vulnerable']);
        $patched = str_contains($source, $markers['patched']);

        if ($patched && !$vulnerable) {
            return self::STATE_PATCHED;
        }

        if ($vulnerable && !$patched) {
            return self::STATE_VULNERABLE;
        }

        return self::STATE_UNKNOWN;
    }

    /**
     * Build the shared evidence payload.
     *
     * @param array<string,string> $states
     * @return array<string,mixed>
     */
    private function evidence(array $states): array
    {
        return [
            'cve' => 'CVE-2026-75650',
            'bulletin' => 'APSB26-146',
            'hotfix' => 'VULN-39341',
            'files' => $states,
            'encryption_key_rotation_verified' => false,
            'remediation' => self::REMEDIATION_TEXT,
        ];
    }
}
