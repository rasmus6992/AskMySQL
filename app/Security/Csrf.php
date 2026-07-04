<?php

declare(strict_types=1);

namespace App\Security;

final class Csrf
{
    private const SESSION_KEY = '_text_to_sql_csrf';

    public static function token(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function validate(?string $providedToken): bool
    {
        $storedToken = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($storedToken)
            && is_string($providedToken)
            && $providedToken !== ''
            && hash_equals($storedToken, $providedToken);
    }
}
