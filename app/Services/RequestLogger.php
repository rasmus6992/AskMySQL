<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class RequestLogger
{
    public function __construct(private PDO $pdo)
    {
    }

    public function log(
        string $ipAddress,
        string $question,
        ?string $generatedSql,
        string $status
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO request_logs (ip_address, question, generated_sql, status, created_at)
             VALUES (:ip_address, :question, :generated_sql, :status, NOW())'
        );

        $statement->execute([
            'ip_address' => $ipAddress,
            'question' => $question,
            'generated_sql' => $generatedSql,
            'status' => $status,
        ]);
    }
}
