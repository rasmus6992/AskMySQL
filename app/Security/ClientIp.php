<?php

declare(strict_types=1);

namespace App\Security;

final class ClientIp
{
    public static function detect(bool $trustProxyHeaders = false): string
    {
        $candidates = [];

        if ($trustProxyHeaders) {
            $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null;
            $candidates[] = $_SERVER['HTTP_X_REAL_IP'] ?? null;

            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
            if (is_string($forwarded)) {
                $candidates = array_merge($candidates, array_map('trim', explode(',', $forwarded)));
            }
        }

        $candidates[] = $_SERVER['REMOTE_ADDR'] ?? null;

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return '0.0.0.0';
    }
}
