<?php

declare(strict_types=1);

use App\Config\AppConfig;
use App\Config\Env;
use App\Helpers\Logger;

$basePath = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($basePath): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $basePath . '/app/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

Env::load($basePath . '/.env');
$config = AppConfig::load($basePath);

date_default_timezone_set((string) $config['app']['timezone']);

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name('text_to_sql_session');
    session_start();
}

return [
    'base_path' => $basePath,
    'config' => $config,
    'logger' => new Logger($basePath . '/storage/logs/app.log'),
];
