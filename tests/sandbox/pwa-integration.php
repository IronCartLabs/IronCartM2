<?php

// phpcs:ignoreFile -- standalone CLI smoke; echo / exit are the right
// tools for the shape (immediate STDOUT for CI logs, numeric exit codes
// for matrix scoring). The script lives under tests/sandbox/ and is
// invoked directly from inside the docker-compose Magento container —
// never loaded as part of the module's autoload graph.
/**
 * IronCart_Scan — PWA Studio check-pack integration smoke (sandbox-only).
 *
 * Runs against the docker-compose Magento sandbox booted by the
 * `integration-pwa` CI cell in `.github/workflows/ci.yml`.
 *
 * Detection strategy
 * ------------------
 * The official Adobe PWA Studio scaffolding (`@magento/pwa-studio`,
 * `@magento/venia-ui`, …) is an npm storefront, not a Magento module —
 * dropping the whole Venia tree into CI would balloon the cell well
 * past the existing 30-minute timeout (npm install + webpack build).
 * Per the issue ("composer require magento/pwa OR a fixture
 * package.json + filesystem markers — pick whichever is cheaper to
 * keep green in CI"), we pick the cheaper path:
 *
 *   - Write a fixture `package.json` at the Magento root referencing
 *     `@magento/pwa-studio` + `@magento/venia-ui` in `devDependencies`.
 *   - Write a fixture `pwa-studio.config.json` next to it.
 *   - That's two cheap signals the existing PwaStudioDetector recognises;
 *     no npm install required.
 *
 * The CI cell also forces:
 *   - `MAGE_MODE=production` (so IC-921 has a chance to fire),
 *   - `graphql/validation/disable_introspection = 0`
 *     (so IC-921 fires deterministically),
 *   - `graphql/validation/maximum_query_depth` and
 *     `graphql/validation/maximum_query_complexity` unset
 *     (so IC-922 fires),
 *   - `web/graphql/cors_allowed_origins = *`
 *     (so IC-923 fires HIGH).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 *
 * @group integration
 */

declare(strict_types=1);

use IronCart\Scan\Check\CheckRegistry;
use IronCart\Scan\Check\PwaStudio\GraphQlCorsWildcardCheck;
use IronCart\Scan\Check\PwaStudio\GraphQlIntrospectionCheck;
use IronCart\Scan\Check\PwaStudio\GraphQlQueryComplexityCheck;
use IronCart\Scan\Check\PwaStudio\PwaStudioDetector;
use IronCart\Scan\Report\Severity;
use Magento\Framework\App\Bootstrap;

$magentoRoot = ironcart_pwa_resolve_magento_root(__DIR__);
if ($magentoRoot === null) {
    fwrite(STDERR, "pwa-integration: cannot locate Magento root from " . __DIR__ . "\n");
    exit(1);
}
require $magentoRoot . '/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

// 1. PwaStudioDetector must detect via the fixture package.json + marker.
$detector = $objectManager->get(PwaStudioDetector::class);
if (!$detector->isDetected()) {
    fwrite(STDERR, "pwa-integration: PwaStudioDetector did not detect PWA. Fixture package.json / pwa-studio.config.json missing?\n");
    exit(2);
}
$record = $detector->detect();
if (($record['signals']['npm'] ?? false) !== true) {
    fwrite(STDERR, "pwa-integration: expected npm signal to be true (fixture package.json)\n");
    fwrite(STDERR, json_encode($record, JSON_PRETTY_PRINT) . "\n");
    exit(3);
}
echo "pwa-integration: PwaStudioDetector OK\n";

// 2. IC-921 — introspection allowed AND MAGE_MODE=production. Must fire.
$ic921 = $objectManager->get(GraphQlIntrospectionCheck::class);
$ic921Findings = $ic921->run();
if (!is_array($ic921Findings) || $ic921Findings === []) {
    fwrite(STDERR, "pwa-integration: IC-921 produced no findings despite production + introspection-allowed config\n");
    exit(4);
}
$ic921Hit = false;
foreach ($ic921Findings as $f) {
    if (($f['id'] ?? null) === GraphQlIntrospectionCheck::ID
        && ($f['severity'] ?? null) === Severity::MEDIUM
    ) {
        $ic921Hit = true;
        break;
    }
}
if (!$ic921Hit) {
    fwrite(STDERR, "pwa-integration: IC-921 fired but didn't match expected (id+severity)\n");
    exit(5);
}
echo "pwa-integration: IC-921 OK\n";

// 3. IC-922 — depth + complexity unset/zero, must report gaps.
$ic922 = $objectManager->get(GraphQlQueryComplexityCheck::class);
$ic922Findings = $ic922->run();
if (!is_array($ic922Findings) || $ic922Findings === []) {
    fwrite(STDERR, "pwa-integration: IC-922 produced no findings despite unset depth/complexity\n");
    exit(6);
}
$ic922Hit = null;
foreach ($ic922Findings as $f) {
    if (($f['id'] ?? null) === GraphQlQueryComplexityCheck::ID) {
        $ic922Hit = $f;
        break;
    }
}
if ($ic922Hit === null) {
    fwrite(STDERR, "pwa-integration: IC-922 fired but id mismatch\n");
    exit(7);
}
$gaps = $ic922Hit['evidence']['gaps'] ?? null;
if (!is_array($gaps) || $gaps === []) {
    fwrite(STDERR, "pwa-integration: IC-922 evidence.gaps must be a non-empty array\n");
    fwrite(STDERR, json_encode($ic922Hit, JSON_PRETTY_PRINT) . "\n");
    exit(8);
}
echo sprintf("pwa-integration: IC-922 OK (%d gap(s))\n", count($gaps));

// 4. IC-923 — wildcard CORS origin must produce a HIGH severity finding.
$ic923 = $objectManager->get(GraphQlCorsWildcardCheck::class);
$ic923Findings = $ic923->run();
if (!is_array($ic923Findings) || $ic923Findings === []) {
    fwrite(STDERR, "pwa-integration: IC-923 produced no findings despite wildcard CORS origin\n");
    exit(9);
}
$ic923Hit = false;
foreach ($ic923Findings as $f) {
    if (($f['id'] ?? null) === GraphQlCorsWildcardCheck::ID
        && ($f['severity'] ?? null) === Severity::HIGH
    ) {
        $ic923Hit = true;
        break;
    }
}
if (!$ic923Hit) {
    fwrite(STDERR, "pwa-integration: IC-923 fired but didn't match expected (id+severity=HIGH)\n");
    fwrite(STDERR, json_encode($ic923Findings, JSON_PRETTY_PRINT) . "\n");
    exit(10);
}
echo "pwa-integration: IC-923 OK\n";

// 5. Registry must contain IC-921..IC-923.
$registry = $objectManager->get(CheckRegistry::class);
$registeredIds = array_map(
    static fn ($check) => $check::ID ?? '',
    $registry->all()
);
foreach (['IC-921', 'IC-922', 'IC-923'] as $expectedId) {
    if (!in_array($expectedId, $registeredIds, true)) {
        fwrite(STDERR, "pwa-integration: CheckRegistry missing {$expectedId}\n");
        exit(11);
    }
}
echo "pwa-integration: CheckRegistry contains IC-921..IC-923\n";

echo "pwa-integration: PASS\n";
exit(0);

function ironcart_pwa_resolve_magento_root(string $start): ?string
{
    $dir = $start;
    for ($i = 0; $i < 10; $i++) {
        if (is_file($dir . '/app/bootstrap.php')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return null;
}
