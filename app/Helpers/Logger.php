<?php

declare(strict_types=1);

namespace App\Helpers;

use Throwable;

final class Logger
{
    public function __construct(private string $filePath)
    {
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function exception(Throwable $exception, string $reference): void
    {
        $this->error($exception->getMessage(), [
            'reference' => $reference,
            'type' => $exception::class,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $directory = dirname($this->filePath);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $entry = sprintf(
            "[%s] %s: %s %s%s",
            date('c'),
            $level,
            $message,
            $context !== [] ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : '',
            PHP_EOL
        );

        @file_put_contents($this->filePath, $entry, FILE_APPEND | LOCK_EX);
    }
}
