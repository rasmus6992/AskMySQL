<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\QueryExecutionException;
use PDO;
use PDOException;

final class QueryExecutor
{
    public function __construct(
        private PDO $pdo,
        private int $maxRows,
        private int $timeoutMs
    ) {
    }

    /**
     * @return array{columns:list<string>,rows:list<array<string,mixed>>,row_count:int,truncated:bool}
     */
    public function execute(string $validatedSql): array
    {
        $fetchLimit = $this->maxRows + 1;
        $limitedSql = $this->applyResultLimit($validatedSql, $fetchLimit);

        try {
            // MySQL supports this optimizer limit for SELECT statements. Some hosts
            // may disallow it, so failure here is intentionally non-fatal.
            try {
                $this->pdo->exec('SET SESSION MAX_EXECUTION_TIME = ' . $this->timeoutMs);
            } catch (PDOException) {
                // Continue: SQL validation, read-only credentials and row limits remain active.
            }

            $statement = $this->pdo->prepare($limitedSql);
            $statement->execute();

            $columns = [];
            for ($index = 0; $index < $statement->columnCount(); $index++) {
                $meta = $statement->getColumnMeta($index);
                $columns[] = is_array($meta) && isset($meta['name'])
                    ? (string) $meta['name']
                    : 'column_' . ($index + 1);
            }

            if (count(array_unique($columns)) !== count($columns)) {
                $statement->closeCursor();
                throw new QueryExecutionException(
                    'The result contains duplicate column names. Every duplicate field must have a unique alias.'
                );
            }

            $rows = [];
            while (count($rows) < $fetchLimit && ($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $normalized = [];
                foreach ($row as $column => $value) {
                    $normalized[(string) $column] = $this->normalizeCellValue($value);
                }
                $rows[] = $normalized;
            }

            $statement->closeCursor();

            $truncated = count($rows) > $this->maxRows;
            if ($truncated) {
                array_pop($rows);
            }

            return [
                'columns' => $columns,
                'rows' => $rows,
                'row_count' => count($rows),
                'truncated' => $truncated,
            ];
        } catch (PDOException $exception) {
            throw new QueryExecutionException(
                'The generated SELECT query could not be executed against the connected schema.',
                0,
                $exception
            );
        }
    }

    private function applyResultLimit(string $sql, int $fetchLimit): string
    {
        $pattern = '/\s+LIMIT\s+(\d+)(?:\s*,\s*(\d+)|\s+OFFSET\s+(\d+))?\s*$/i';

        if (preg_match($pattern, $sql, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $sql . ' LIMIT ' . $fetchLimit;
        }

        $fullMatch = $match[0][0];
        $offsetPosition = $match[0][1];
        $prefix = substr($sql, 0, $offsetPosition);

        if (isset($match[2]) && $match[2][0] !== '') {
            $offset = (int) $match[1][0];
            $count = min((int) $match[2][0], $fetchLimit);
            return $prefix . sprintf(' LIMIT %d, %d', $offset, $count);
        }

        $count = min((int) $match[1][0], $fetchLimit);
        if (isset($match[3]) && $match[3][0] !== '') {
            return $prefix . sprintf(' LIMIT %d OFFSET %d', $count, (int) $match[3][0]);
        }

        return $prefix . ' LIMIT ' . $count;
    }

    private function normalizeCellValue(mixed $value): mixed
    {
        if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        $string = (string) $value;
        if (preg_match('//u', $string) !== 1) {
            return '[binary data omitted]';
        }

        $maxCellLength = 5000;
        if (function_exists('mb_strlen') && mb_strlen($string, 'UTF-8') > $maxCellLength) {
            return mb_substr($string, 0, $maxCellLength, 'UTF-8') . '…';
        }

        if (!function_exists('mb_strlen') && strlen($string) > $maxCellLength) {
            return substr($string, 0, $maxCellLength) . '…';
        }

        return $string;
    }
}
