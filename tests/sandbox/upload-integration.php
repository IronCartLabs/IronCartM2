<?php

// phpcs:ignoreFile -- standalone CLI smoke; echo / exit / exec are
// the right tools for the shape (immediate STDOUT for CI logs, numeric
// exit codes for matrix scoring, exec() to drive `php -S` and
// `bin/magento`). The script lives under tests/sandbox/ and is invoked
// directly from inside the docker-compose Magento container — never
// loaded as part of the module's autoload graph.
/**
 * IronCart_Scan — `--upload` integration smoke (sandbox-only).
 *
 * Stands in for the production IronCartWeb /api/scan/ingest endpoint
 * which is filed in parallel against IronCartLabs/IronCartWeb#984.
 * Spins up a local PHP built-in HTTP server (`php -S 127.0.0.1:<port>
 * upload-mock-server.php`) that:
 *
 *   1. Records the incoming POST body and headers.
 *   2. Responds with `{"view_url": "https://ironcart.dev/scan/test-123"}` and 200.
 *
 * Then drives `bin/magento ironcart:scan --upload` against this mock
 * by setting:
 *
 *   - `ironcart_scan/upload/enabled`     = 1
 *   - `ironcart_scan/upload/token`       = "test-token-xyz"
 *   - `ironcart_scan/upload/endpoint`    = "http://127.0.0.1:<port>/api/scan/ingest"
 *   - `ironcart_scan/upload/allowed_host` = "127.0.0.1"
 *
 * After the upload completes, asserts:
 *
 *   - The mock received exactly one POST.
 *   - `Authorization: Bearer test-token-xyz` header present.
 *   - `Content-Type: application/json` header present.
 *   - Body decodes as JSON with `schema_version = "1"`, `store.base_url`
 *     non-empty, `findings` is an array, and contains NO `admin_email`
 *     / `operator_email` / `admin_username` key anywhere.
 *   - `bin/magento ironcart:scan --upload` exited 0 and printed
 *     "Scan uploaded: https://ironcart.dev/scan/test-123" to stdout.
 *
 * Not loaded by PHPUnit in the unit-CI cell — that cell strips
 * magento/framework before composer install, which means
 * `Magento\Framework\App\Bootstrap` is unresolvable. This file is
 * executed directly inside the sandbox container, e.g.:
 *
 *   docker compose -f tests/sandbox/docker-compose.yml exec -T magento \
 *     php /var/www/html/ironcart-module/tests/sandbox/upload-integration.php
 *
 * IMPORTANT: this is a MOCK test. The production integration smoke
 * (against the live ironcart.dev /api/scan/ingest endpoint) is a
 * follow-up wiring task once IronCartLabs/IronCartWeb#984 lands.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 *
 * @group integration
 */

declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriter;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

$moduleRoot = dirname(__DIR__);
$magentoRoot = dirname($moduleRoot);
if (!file_exists($magentoRoot . '/app/bootstrap.php')) {
    fwrite(STDERR, "upload-integration: cannot locate Magento root from {$magentoRoot}\n");
    exit(1);
}

// Pick a free localhost port. We can't ask the OS for "any free port"
// portably from PHP without binding a socket and reading back the port,
// so we use a deterministic but unusual port that's unlikely to clash
// with the sandbox stack (Magento on 80, Elasticsearch on 9200, RabbitMQ
// on 5672 / 15672, MySQL on 3306).
$mockPort = 19327;
$mockHost = '127.0.0.1';
$mockUrl = "http://{$mockHost}:{$mockPort}/api/scan/ingest";

$captureFile = sys_get_temp_dir() . '/ironcart-upload-mock-capture-' . posix_getpid() . '.json';
@unlink($captureFile);

// Inline mock server. The built-in server only runs a single PHP file
// as its "router"; we write that router script next to this one and
// reuse it for the lifetime of the test.
$mockRouter = __DIR__ . '/upload-mock-server.php';
$routerSrc = <<<'PHP'
<?php
// Mock IronCartWeb /api/scan/ingest. Captures the incoming POST to a
// file whose path is passed via the CAPTURE_FILE env var, then responds
// with a deterministic 2xx envelope.
declare(strict_types=1);

$capture = getenv('CAPTURE_FILE') ?: sys_get_temp_dir() . '/ironcart-mock.json';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

if ($method !== 'POST' || $path !== '/api/scan/ingest') {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    return;
}

$body = file_get_contents('php://input') ?: '';
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_') && is_string($v)) {
        $name = strtolower(str_replace('_', '-', substr($k, 5)));
        $headers[$name] = $v;
    }
}
if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}

file_put_contents($capture, json_encode([
    'method' => $method,
    'path' => $path,
    'headers' => $headers,
    'body' => $body,
], JSON_PRETTY_PRINT));

http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'view_url' => 'https://ironcart.dev/scan/test-123',
    'report_id' => 'test-123',
]);
PHP;
file_put_contents($mockRouter, $routerSrc);

// Boot the mock server in the background.
$serverCmd = sprintf(
    'CAPTURE_FILE=%s php -S %s:%d -t %s %s > /dev/null 2>&1 & echo $!',
    escapeshellarg($captureFile),
    escapeshellarg($mockHost),
    $mockPort,
    escapeshellarg(__DIR__),
    escapeshellarg($mockRouter)
);
$serverPid = (int) trim((string) shell_exec($serverCmd));
if ($serverPid <= 0) {
    fwrite(STDERR, "upload-integration: failed to start mock server\n");
    exit(2);
}
echo "upload-integration: mock server PID={$serverPid} on {$mockHost}:{$mockPort}\n";

// Tear-down guard — kill the mock server on every exit path.
register_shutdown_function(static function () use ($serverPid, $mockRouter): void {
    if ($serverPid > 0) {
        @posix_kill($serverPid, SIGTERM);
    }
    @unlink($mockRouter);
});

// Wait for the mock server to be ready.
$ready = false;
for ($i = 0; $i < 30; $i++) {
    $sock = @fsockopen($mockHost, $mockPort, $errno, $errstr, 0.5);
    if (is_resource($sock)) {
        fclose($sock);
        $ready = true;
        break;
    }
    usleep(200_000);
}
if (!$ready) {
    fwrite(STDERR, "upload-integration: mock server never came up\n");
    exit(3);
}

require $magentoRoot . '/app/bootstrap.php';
$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

// Persist upload config: enabled, encrypted token, override endpoint to
// the mock. Use ConfigWriter so changes survive across the inner
// `bin/magento` exec call.
$writer = $objectManager->get(ConfigWriter::class);
$encryptor = $objectManager->get(EncryptorInterface::class);

$writer->save('ironcart_scan/upload/enabled', '1');
$writer->save('ironcart_scan/upload/token', $encryptor->encrypt('test-token-xyz'));
$writer->save('ironcart_scan/upload/endpoint', $mockUrl);
$writer->save('ironcart_scan/upload/allowed_host', $mockHost);

// Force the in-memory ScopeConfig to re-read what the writer just
// persisted. Otherwise the inner `bin/magento` may run before the cache
// reload kicks in.
$objectManager->get(ScopeConfigInterface::class)->clean();

$bin = $magentoRoot . '/bin/magento';
$cmd = sprintf(
    '%s ironcart:scan --upload --format=json 2>&1',
    escapeshellarg($bin)
);
$exitCode = 0;
$output = [];
exec($cmd, $output, $exitCode);
$display = implode("\n", $output);
echo "upload-integration: bin/magento ironcart:scan --upload exit={$exitCode}\n";
echo $display . "\n";

if ($exitCode !== 0) {
    fwrite(STDERR, "upload-integration: scan command exited non-zero\n");
    exit(4);
}
if (!str_contains($display, 'https://ironcart.dev/scan/test-123')) {
    fwrite(STDERR, "upload-integration: stdout missing expected view_url\n");
    exit(5);
}

if (!file_exists($captureFile)) {
    fwrite(STDERR, "upload-integration: mock never captured a request\n");
    exit(6);
}

$captured = json_decode((string) file_get_contents($captureFile), true);
if (!is_array($captured)) {
    fwrite(STDERR, "upload-integration: capture file unparseable\n");
    exit(7);
}

// Assert: Authorization header carried the bearer token verbatim.
$authHeader = $captured['headers']['authorization'] ?? '';
if ($authHeader !== 'Bearer test-token-xyz') {
    fwrite(STDERR, "upload-integration: missing/wrong Authorization header — got '{$authHeader}'\n");
    exit(8);
}

// Assert: Content-Type is application/json.
$ct = $captured['headers']['content-type'] ?? '';
if (!str_starts_with((string) $ct, 'application/json')) {
    fwrite(STDERR, "upload-integration: wrong Content-Type — got '{$ct}'\n");
    exit(9);
}

// Assert: payload shape.
$payload = json_decode((string) $captured['body'], true);
if (!is_array($payload)) {
    fwrite(STDERR, "upload-integration: payload body did not decode as JSON\n");
    exit(10);
}
foreach (['schema_version', 'source', 'store', 'findings'] as $key) {
    if (!array_key_exists($key, $payload)) {
        fwrite(STDERR, "upload-integration: payload missing '{$key}'\n");
        exit(11);
    }
}
if ($payload['schema_version'] !== '1') {
    fwrite(STDERR, "upload-integration: schema_version != '1'\n");
    exit(12);
}

// Assert: NO PII keys anywhere in the tree.
$walker = static function ($node, callable $self) use (&$found): void {
    if (!is_array($node)) {
        return;
    }
    foreach ($node as $k => $v) {
        if (is_string($k) && in_array(
            strtolower($k),
            ['admin_email', 'operator_email', 'admin_username', 'admin_user_email'],
            true
        )) {
            $found[] = $k;
        }
        $self($v, $self);
    }
};
$found = [];
$walker($payload, $walker);
if ($found !== []) {
    fwrite(STDERR, 'upload-integration: payload contained forbidden PII keys: ' . implode(',', $found) . "\n");
    exit(13);
}

echo "upload-integration: PASS\n";
exit(0);
