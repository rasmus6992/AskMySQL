<?php

declare(strict_types=1);

namespace App\Config;

use PDO;

final class Database
{
    /** @param array<string, mixed> $config */
    public static function connect(array $config, bool $unbuffered = false): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['name'],
            $config['charset']
        );

        return new PDO($dsn, (string) $config['user'], (string) $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => !$unbuffered,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . $config['charset'],
        ]);
    }
}
