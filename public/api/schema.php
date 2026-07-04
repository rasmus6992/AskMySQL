<?php

declare(strict_types=1);

use App\Config\Database;
use App\Helpers\JsonResponse;
use App\Security\ClientIp;
use App\Services\RateLimiter;
use App\Services\SchemaReader;

$bootstrap = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$config = $bootstrap['config'];
$logger = $bootstrap['logger'];
$reference = strtoupper(bin2hex(random_bytes(4)));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    JsonResponse::send([
        'success' => false,
        'message' => 'Only GET requests are allowed.',
    ], 405);
}

try {
    $appPdo = Database::connect($config['database']['app']);
    $targetPdo = Database::connect($config['database']['target']);

    $schemaReader = new SchemaReader(
        $targetPdo,
        (string) $config['database']['target']['name'],
        $config['security']['excluded_tables']
    );
    $schema = $schemaReader->read();

    $rateLimiter = new RateLimiter(
        $appPdo,
        (int) $config['security']['rate_limit_max'],
        (int) $config['security']['rate_limit_window_hours']
    );

    $ipAddress = ClientIp::detect((bool) $config['security']['trust_proxy_headers']);
    $rateStatus = $rateLimiter->status($ipAddress);

    $columnCount = 0;
    foreach ($schema as $table) {
        $columnCount += count($table['columns']);
    }

    JsonResponse::send([
        'success' => true,
        'database' => (string) $config['database']['target']['name'],
        'tables' => array_values($schema),
        'stats' => [
            'tables' => count($schema),
            'columns' => $columnCount,
        ],
        'rate_limit' => $rateStatus,
    ]);
} catch (Throwable $exception) {
    $logger->exception($exception, $reference);

    JsonResponse::send([
        'success' => false,
        'message' => 'The database schema could not be loaded. Check the server configuration.',
        'reference' => $reference,
    ], 500);
}
