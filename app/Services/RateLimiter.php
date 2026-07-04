<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;

final class RateLimiter
{
    public function __construct(
        private PDO $pdo,
        private int $maxRequests,
        private int $windowHours
    ) {
    }

    /** @return array{allowed:bool,count:int,remaining:int,reset_at:?string} */
    public function consume(string $ipAddress): array
    {
        return $this->consumeAttempt($ipAddress, 0);
    }

    /** @return array{allowed:bool,count:int,remaining:int,reset_at:?string} */
    public function status(string $ipAddress): array
    {
        $statement = $this->pdo->prepare(
            'SELECT request_count, created_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed_seconds
             FROM rate_limits
             WHERE ip_address = :ip_address
             LIMIT 1'
        );
        $statement->execute(['ip_address' => $ipAddress]);
        $row = $statement->fetch();

        if ($row === false || $this->isExpired((int) $row['elapsed_seconds'])) {
            return [
                'allowed' => true,
                'count' => 0,
                'remaining' => $this->maxRequests,
                'reset_at' => null,
            ];
        }

        $count = (int) $row['request_count'];
        return [
            'allowed' => $count < $this->maxRequests,
            'count' => $count,
            'remaining' => max(0, $this->maxRequests - $count),
            'reset_at' => $this->calculateResetAt((string) $row['created_at']),
        ];
    }

    /** @return array{allowed:bool,count:int,remaining:int,reset_at:?string} */
    private function consumeAttempt(string $ipAddress, int $attempt): array
    {
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'SELECT id, request_count, created_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed_seconds
                 FROM rate_limits
                 WHERE ip_address = :ip_address
                 FOR UPDATE'
            );
            $statement->execute(['ip_address' => $ipAddress]);
            $row = $statement->fetch();

            if ($row === false) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO rate_limits (ip_address, request_count, created_at, updated_at)
                     VALUES (:ip_address, 1, NOW(), NOW())'
                );
                $insert->execute(['ip_address' => $ipAddress]);
                $this->pdo->commit();

                return [
                    'allowed' => true,
                    'count' => 1,
                    'remaining' => max(0, $this->maxRequests - 1),
                    'reset_at' => $this->windowHours > 0
                        ? date('Y-m-d H:i:s', time() + ($this->windowHours * 3600))
                        : null,
                ];
            }

            if ($this->isExpired((int) $row['elapsed_seconds'])) {
                $reset = $this->pdo->prepare(
                    'UPDATE rate_limits
                     SET request_count = 1, created_at = NOW(), updated_at = NOW()
                     WHERE id = :id'
                );
                $reset->execute(['id' => $row['id']]);
                $this->pdo->commit();

                return [
                    'allowed' => true,
                    'count' => 1,
                    'remaining' => max(0, $this->maxRequests - 1),
                    'reset_at' => date('Y-m-d H:i:s', time() + ($this->windowHours * 3600)),
                ];
            }

            $count = (int) $row['request_count'];
            if ($count >= $this->maxRequests) {
                $this->pdo->commit();

                return [
                    'allowed' => false,
                    'count' => $count,
                    'remaining' => 0,
                    'reset_at' => $this->calculateResetAt((string) $row['created_at']),
                ];
            }

            $count++;
            $update = $this->pdo->prepare(
                'UPDATE rate_limits
                 SET request_count = :request_count, updated_at = NOW()
                 WHERE id = :id'
            );
            $update->execute([
                'request_count' => $count,
                'id' => $row['id'],
            ]);
            $this->pdo->commit();

            return [
                'allowed' => true,
                'count' => $count,
                'remaining' => max(0, $this->maxRequests - $count),
                'reset_at' => $this->calculateResetAt((string) $row['created_at']),
            ];
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // A simultaneous first request can race on the unique IP index. Retry once.
            if ($attempt === 0 && (string) $exception->getCode() === '23000') {
                return $this->consumeAttempt($ipAddress, 1);
            }

            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function isExpired(int $elapsedSeconds): bool
    {
        return $this->windowHours > 0 && $elapsedSeconds >= ($this->windowHours * 3600);
    }

    private function calculateResetAt(string $createdAt): ?string
    {
        if ($this->windowHours === 0) {
            return null;
        }

        $timestamp = strtotime($createdAt);
        return $timestamp === false
            ? null
            : date('Y-m-d H:i:s', $timestamp + ($this->windowHours * 3600));
    }
}
