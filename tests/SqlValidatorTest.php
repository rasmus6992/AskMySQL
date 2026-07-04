<?php

declare(strict_types=1);

use App\Exceptions\SqlValidationException;
use App\Services\SqlValidator;

require dirname(__DIR__) . '/bootstrap/app.php';

$schema = [
    'cities' => [
        'name' => 'cities',
        'columns' => [
            ['name' => 'id'],
            ['name' => 'name'],
        ],
        'foreign_keys' => [],
    ],
    'sales' => [
        'name' => 'sales',
        'columns' => [
            ['name' => 'id'],
            ['name' => 'city_id'],
            ['name' => 'sale_date'],
            ['name' => 'net_amount'],
            ['name' => 'status'],
        ],
        'foreign_keys' => [],
    ],
];

$validator = new SqlValidator(['request_logs', 'rate_limits']);

$validQueries = [
    "SELECT c.name AS city_name, SUM(s.net_amount) AS total_sales FROM sales AS s JOIN cities AS c ON c.id = s.city_id WHERE s.sale_date BETWEEN '2026-06-01' AND '2026-06-05' GROUP BY c.name ORDER BY total_sales DESC",
    "SELECT s.sale_date AS sale_day, COUNT(*) AS sale_count FROM sales AS s WHERE s.status = 'completed' GROUP BY s.sale_date LIMIT 10",
];

$invalidQueries = [
    'DELETE FROM sales',
    'SELECT * FROM unknown_table AS u',
    'SELECT s.unknown_column FROM sales AS s',
    'SELECT s.id FROM sales AS s UNION SELECT c.id FROM cities AS c',
    'SELECT s.id FROM sales AS s WHERE s.city_id IN (SELECT c.id FROM cities AS c)',
    'SELECT s.id FROM sales AS s -- comment',
    'SELECT * FROM information_schema.tables AS t',
];

$failures = [];

foreach ($validQueries as $query) {
    try {
        $validator->validate($query, $schema);
    } catch (Throwable $exception) {
        $failures[] = 'Expected valid query to pass: ' . $exception->getMessage();
    }
}

foreach ($invalidQueries as $query) {
    try {
        $validator->validate($query, $schema);
        $failures[] = 'Expected invalid query to be blocked: ' . $query;
    } catch (SqlValidationException) {
        // Expected.
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "SqlValidator tests passed." . PHP_EOL;
