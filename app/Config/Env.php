<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Small dependency-free .env loader suitable for shared hosting.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $filePath): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));

            if ($key === '' || preg_match('/^[A-Z0-9_]+$/i', $key) !== 1) {
                continue;
            }

            if (
                strlen($value) >= 2
                && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))
            ) {
                $quote = $value[0];
                $value = substr($value, 1, -1);
                if ($quote === '"') {
                    $value = stripcslashes($value);
                }
            } else {
                // Allow inline comments only when preceded by whitespace.
                $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
                $value = trim($value);
            }

            self::$values[$key] = $value;
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = self::$values[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return $value !== null && filter_var($value, FILTER_VALIDATE_INT) !== false
            ? (int) $value
            : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        return $parsed ?? $default;
    }
}
