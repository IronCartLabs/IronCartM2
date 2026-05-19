<?php

// phpcs:ignoreFile -- standalone CLI smoke; echo / exit are the right
// tools for the shape (immediate STDOUT for CI logs, numeric exit codes
// for matrix scoring). The script lives under tests/sandbox/ and is
// invoked directly from inside the docker-compose Magento container —
// never loaded as part of the module's autoload graph.
/**
 * IronCart_Scan — Hyvä check-pack integration smoke (sandbox-only).
 *
 * Runs against the docker-compose Magento sandbox booted by the
 * `integration-hyva` CI cell in `.github/workflows/ci.yml`. The cell:
 *
 *   1. Stands up a Magento sandbox (composer create-project + the module
 *      dropped into `app/code/IronCart/Scan/`, the same pattern as the
 *      default Luma integration cell).
 *   2. Adds `hyva-themes/magento2-default-theme` via
 *      `composer require hyva-themes/magento2-default-theme`. That pulls
 *      `Hyva_Theme` into `app/code/` / `vendor/`, which the existing
 *      `Check/Hyva/HyvaDetector` picks up.
 *   3. Drops a single deliberately-CDN-loaded Alpine.js fixture template
 *      under `app/design/frontend/Hyva/_ironcart_fixture/` so IC-913
 *      has a known finding to assert against.
 *   4. Invokes this driver, which:
 *      - Boots Magento via `app/bootstrap.php`.
 *      - Resolves the `HyvaDetector` from the object manager and asserts
 *        `isDetected() === true` (otherwise the Hyvä pack short-circuits
 *        to zero findings and the whole cell is meaningless).
 *      - Resolves IC-910 / IC-911 / IC-912 / IC-913 from the
 *        CheckRegistry, runs each, and asserts the contracted shape:
 *          - IC-910 / IC-911 / IC-912 produce zero or more findings
 *            without throwing; on a clean sandbox we tolerate any
 *            baseline (the manifests are seed placeholders per #125).
 *          - IC-913 fires AT LEAST ONE finding because of the CDN
 *            fixture template, and the matched URL must be the one we
 *            planted.
 *      - Exits 0 on success, non-zero on any assertion miss.
 *
 * The corresponding workflow cell is gated on the same
 * INTEGRATION_ENABLED repo variable as the default integration job
 * (issue #18 wiring).
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 *
 * @group integration
 */

declare(strict_types=1);

use IronCart\Scan\Check\CheckRegistry;
use IronCart\Scan\Check\Hyva\AlpineCdnUsageCheck;
use IronCart\Scan\Check\Hyva\CheckoutCspRegressionCheck;
use IronCart\Scan\Check\Hyva\HyvaDetector;
use IronCart\Scan\Check\Hyva\HyvaModuleDriftCheck;
use IronCart\Scan\Check\Hyva\TailwindConfigExposureCheck;
use Magento\Framework\App\Bootstrap;

$magentoRoot = ironcart_hyva_resolve_magento_root(__DIR__);
if ($magentoRoot === null) {
    fwrite(STDERR, "hyva-integration: cannot locate Magento root from " . __DIR__ . "\n");
    exit(1);
}
require $magentoRoot . '/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

// 1. Detector must flip to true. The CI cell installs
// `hyva-themes/magento2-default-theme` before this driver runs, so the
// composer-lock + Hyva_Theme module signals are both expected.
$detector = $objectManager->get(HyvaDetector::class);
if (!$detector->isDetected()) {
    fwrite(STDERR, "hyva-integration: HyvaDetector did not detect Hyvä. Composer install of hyva-themes/* missing?\n");
    exit(2);
}
echo "hyva-integration: HyvaDetector OK\n";

// 2. IC-910 / IC-911 / IC-912 must run without throwing. We do NOT
//    assert specific findings on these three because their inputs
//    (pub/static layout, csp_whitelist.xml hashes, hyva-themes/*
//    version floor) drift across the matrix; the unit tests
//    (Test/Unit/Check/Hyva/*) own the per-input assertions. The
//    integration cell's job is to prove the checks run end-to-end
//    against a real Magento boot and never throw.
foreach (
    [
        TailwindConfigExposureCheck::class,
        CheckoutCspRegressionCheck::class,
        HyvaModuleDriftCheck::class,
    ] as $checkClass
) {
    try {
        $check = $objectManager->get($checkClass);
        $findings = $check->run();
    } catch (\Throwable $e) {
        fwrite(STDERR, sprintf(
            "hyva-integration: %s threw %s: %s\n",
            $checkClass,
            $e::class,
            $e->getMessage()
        ));
        exit(3);
    }
    if (!is_array($findings)) {
        fwrite(STDERR, "hyva-integration: {$checkClass}->run() must return array\n");
        exit(4);
    }
    echo sprintf(
        "hyva-integration: %s OK (%d findings)\n",
        $checkClass,
        count($findings)
    );
}

// 3. IC-913 — the headline assertion. The CI cell planted a fixture
//    template under app/design/frontend/Hyva/_ironcart_fixture/ that
//    loads Alpine.js from cdn.jsdelivr.net. The check MUST flag it.
$ic913 = $objectManager->get(AlpineCdnUsageCheck::class);
$ic913Findings = $ic913->run();
if (!is_array($ic913Findings) || $ic913Findings === []) {
    fwrite(STDERR, "hyva-integration: IC-913 produced no findings despite the CDN-Alpine fixture\n");
    exit(5);
}
$matched = false;
foreach ($ic913Findings as $finding) {
    if (($finding['id'] ?? null) !== AlpineCdnUsageCheck::ID) {
        continue;
    }
    $matches = $finding['evidence']['matches'] ?? [];
    if (!is_array($matches)) {
        continue;
    }
    foreach ($matches as $match) {
        $url = $match['url'] ?? '';
        if (is_string($url) && str_contains($url, 'cdn.jsdelivr.net') && stripos($url, 'alpine') !== false) {
            $matched = true;
            break 2;
        }
    }
}
if (!$matched) {
    fwrite(STDERR, "hyva-integration: IC-913 fired but did not flag the cdn.jsdelivr.net Alpine fixture\n");
    fwrite(STDERR, json_encode($ic913Findings, JSON_PRETTY_PRINT) . "\n");
    exit(6);
}
echo "hyva-integration: IC-913 OK (CDN fixture flagged)\n";

// 4. Sanity: CheckRegistry must list every IC-91x ID. If the di.xml
//    drifts out of sync with the check classes the registry won't
//    return them, which would silently disable IC-91x findings in the
//    `ironcart:scan` CLI even though the unit tests pass.
$registry = $objectManager->get(CheckRegistry::class);
$registeredIds = array_map(
    static fn ($check) => $check::ID ?? '',
    $registry->all()
);
foreach (['IC-910', 'IC-911', 'IC-912', 'IC-913'] as $expectedId) {
    if (!in_array($expectedId, $registeredIds, true)) {
        fwrite(STDERR, "hyva-integration: CheckRegistry missing {$expectedId}\n");
        exit(7);
    }
}
echo "hyva-integration: CheckRegistry contains IC-910..IC-913\n";

echo "hyva-integration: PASS\n";
exit(0);

/**
 * Walk parents looking for `app/bootstrap.php` so the driver works
 * regardless of where the module ended up under `app/code/`. Mirrors
 * the resolveMagentoRoot() helper that IC-913 itself uses internally.
 */
function ironcart_hyva_resolve_magento_root(string $start): ?string
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
