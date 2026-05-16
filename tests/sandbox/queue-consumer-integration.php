<?php

// phpcs:ignoreFile -- standalone CLI smoke; echo / exit / exec are
// the right tools for the shape (immediate STDOUT for CI logs, numeric
// exit codes for matrix scoring, exec() to drive `bin/magento`). The
// script lives under tests/sandbox/ and is invoked directly from inside
// the docker-compose Magento container — never loaded as part of the
// module's autoload graph.
/**
 * IronCart_Scan — async-queue integration smoke (sandbox-only).
 *
 * Runs against the docker-compose Magento sandbox booted by the
 * `integration` CI job. Boots a Magento app, publishes a single
 * `ironcart.scan.run` message via ScanRunPublisher, drives the
 * consumer in single-message mode, and asserts:
 *
 *   - the row in `ironcart_scan_run` reaches status=succeeded
 *   - at least one row exists in `ironcart_scan_finding` for that run
 *   - `summary_json` carries the v0 totals shape
 *
 * Not loaded by PHPUnit in the unit-CI cell — that cell strips
 * magento/framework before composer install, which means
 * `Magento\Framework\App\Bootstrap` is unresolvable. This file is
 * executed directly inside the sandbox container, e.g.:
 *
 *   docker compose -f tests/sandbox/docker-compose.yml exec -T magento \
 *     php /var/www/html/ironcart-module/tests/sandbox/queue-consumer-integration.php
 *
 * Wiring this invocation into the `integration` job in
 * .github/workflows/ci.yml is a follow-up — that path is under
 * CODEOWNERS and intentionally left to the agent:ops PR that toggles
 * INTEGRATION_ENABLED (#18 / PR #32) so the queue smoke lands the
 * same time the matrix actually executes.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

use IronCart\Scan\Model\ScanFinding;
use IronCart\Scan\Model\ScanRun;
use IronCart\Scan\Model\ScanRunPublisher;
use IronCart\Scan\Model\ResourceModel\ScanFinding as ScanFindingResource;
use IronCart\Scan\Model\ResourceModel\ScanRun as ScanRunResource;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;

// Locate the Magento root. The sandbox installs Magento at /var/www/html
// (see tests/sandbox/docker-compose.yml — the magento container bind-
// mounts the host source there); the module itself lives at
// /var/www/html/ironcart-module. We start from this script's __DIR__,
// climb two levels to the module root, then resolve the Magento root
// relative to it.
$moduleRoot = dirname(__DIR__);
$magentoRoot = dirname($moduleRoot);
if (!file_exists($magentoRoot . '/app/bootstrap.php')) {
    fwrite(STDERR, "queue-consumer-integration: cannot locate Magento root from {$magentoRoot}\n");
    exit(1);
}
require $magentoRoot . '/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

// 1. Publish a run.
$publisher = $objectManager->get(ScanRunPublisher::class);
$runId = $publisher->publish('cli');
echo "queue-consumer-integration: published scan run #{$runId}\n";

// 2. Drive the consumer in single-message mode.
//    We shell out to bin/magento so we exercise exactly the path
//    Magento operators run — `queue:consumers:start` is a thin wrapper
//    over Magento\MessageQueue\Console\StartConsumerCommand, which
//    pumps messages until --max-messages is reached.
$bin = $magentoRoot . '/bin/magento';
$cmd = sprintf(
    '%s queue:consumers:start ironcartScanRunConsumer --single-thread --max-messages=1 2>&1',
    escapeshellarg($bin)
);
$exitCode = 0;
$output = [];
exec($cmd, $output, $exitCode);
echo "queue-consumer-integration: consumer exit={$exitCode}\n";
echo implode("\n", $output) . "\n";
if ($exitCode !== 0) {
    fwrite(STDERR, "queue-consumer-integration: consumer did not exit cleanly\n");
    exit(2);
}

// 3. Assert the run row transitioned to `succeeded`.
$run = $objectManager->create(ScanRun::class);
$objectManager->get(ScanRunResource::class)->load($run, $runId);
$status = $run->getStatus();
if ($status !== ScanRun::STATUS_SUCCEEDED) {
    fwrite(STDERR, "queue-consumer-integration: expected status=succeeded, got {$status}\n");
    fwrite(STDERR, 'summary_json: ' . (string)$run->getSummaryJson() . "\n");
    exit(3);
}
echo "queue-consumer-integration: run #{$runId} status=succeeded\n";

// 4. Assert at least one finding exists for this run.
$findingResource = $objectManager->get(ScanFindingResource::class);
$connection = $objectManager->get(ResourceConnection::class)->getConnection();
$count = (int)$connection->fetchOne(
    $connection->select()
        ->from($findingResource->getMainTable(), ['c' => 'COUNT(*)'])
        ->where('scan_run_id = ?', $runId)
);
if ($count < 1) {
    fwrite(STDERR, "queue-consumer-integration: expected >=1 finding for run #{$runId}, got {$count}\n");
    exit(4);
}
echo "queue-consumer-integration: run #{$runId} has {$count} findings — OK\n";

// 5. Assert summary_json carries the v0 totals shape.
$summary = json_decode((string)$run->getSummaryJson(), true);
if (!is_array($summary) || !isset($summary['totals']) || !is_array($summary['totals'])) {
    fwrite(STDERR, "queue-consumer-integration: summary_json missing `totals` map\n");
    exit(5);
}
foreach (['critical', 'high', 'medium', 'low', 'info'] as $severity) {
    if (!array_key_exists($severity, $summary['totals'])) {
        fwrite(STDERR, "queue-consumer-integration: summary_json.totals missing '{$severity}' key\n");
        exit(6);
    }
}
echo "queue-consumer-integration: PASS\n";
exit(0);
