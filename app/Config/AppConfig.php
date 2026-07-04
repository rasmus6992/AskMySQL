<?php

declare(strict_types=1);

namespace App\Config;

final class AppConfig
{
    /** @return array<string, mixed> */
    public static function load(string $basePath): array
    {
        $appDatabase = [
            'host' => Env::get('APP_DB_HOST', '127.0.0.1'),
            'port' => Env::int('APP_DB_PORT', 3306),
            'name' => Env::get('APP_DB_NAME', 'text_to_sql'),
            'user' => Env::get('APP_DB_USER', 'root'),
            'password' => Env::get('APP_DB_PASSWORD', ''),
            'charset' => Env::get('APP_DB_CHARSET', 'utf8mb4'),
        ];

        $targetDatabase = [
            'host' => Env::get('TARGET_DB_HOST', (string) $appDatabase['host']),
            'port' => Env::int('TARGET_DB_PORT', (int) $appDatabase['port']),
            'name' => Env::get('TARGET_DB_NAME', (string) $appDatabase['name']),
            'user' => Env::get('TARGET_DB_USER', (string) $appDatabase['user']),
            'password' => Env::get('TARGET_DB_PASSWORD', (string) $appDatabase['password']),
            'charset' => Env::get('TARGET_DB_CHARSET', (string) $appDatabase['charset']),
        ];

        $excludedTables = array_values(array_unique(array_filter(array_map(
            static fn (string $table): string => strtolower(trim($table)),
            explode(',', Env::get('TARGET_DB_EXCLUDED_TABLES', 'request_logs,rate_limits') ?? '')
        ))));

        return [
            'base_path' => $basePath,
            'app' => [
                'name' => Env::get('APP_NAME', 'AskMySQL'),
                'environment' => Env::get('APP_ENV', 'production'),
                'debug' => Env::bool('APP_DEBUG', false),
                'timezone' => Env::get('APP_TIMEZONE', 'Asia/Kolkata'),
            ],
            'database' => [
                'app' => $appDatabase,
                'target' => $targetDatabase,
            ],
            'openai' => [
                'api_key' => Env::get('OPENAI_API_KEY', ''),
                'model' => Env::get('OPENAI_MODEL', 'gpt-5.5'),
                'endpoint' => Env::get('OPENAI_ENDPOINT', 'https://api.openai.com/v1/responses'),
                'timeout_seconds' => max(10, Env::int('OPENAI_TIMEOUT_SECONDS', 45)),
            ],
            'security' => [
                'rate_limit_max' => max(1, Env::int('RATE_LIMIT_MAX_REQUESTS', 10)),
                'rate_limit_window_hours' => max(0, Env::int('RATE_LIMIT_WINDOW_HOURS', 24)),
                'max_result_rows' => max(1, min(1000, Env::int('MAX_RESULT_ROWS', 200))),
                'max_question_length' => max(50, min(5000, Env::int('MAX_QUESTION_LENGTH', 1000))),
                'query_timeout_ms' => max(1000, min(60000, Env::int('QUERY_TIMEOUT_MS', 8000))),
                'trust_proxy_headers' => Env::bool('TRUST_PROXY_HEADERS', false),
                'excluded_tables' => $excludedTables,
            ],
        ];
    }
}
