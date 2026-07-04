<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class SchemaReader
{
    /** @param list<string> $excludedTables */
    public function __construct(
        private PDO $pdo,
        private string $databaseName,
        private array $excludedTables = []
    ) {
    }

    /**
     * @return array<string, array{
     *   name: string,
     *   columns: list<array{name:string,type:string,nullable:bool,key:string,default:mixed,extra:string}>,
     *   foreign_keys: list<array{column:string,referenced_table:string,referenced_column:string}>
     * }>
     */
    public function read(): array
    {
        $columnSql = <<<'SQL'
            SELECT
                TABLE_NAME,
                COLUMN_NAME,
                COLUMN_TYPE,
                IS_NULLABLE,
                COLUMN_KEY,
                COLUMN_DEFAULT,
                EXTRA,
                ORDINAL_POSITION
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :schema
            ORDER BY TABLE_NAME, ORDINAL_POSITION
        SQL;

        $statement = $this->pdo->prepare($columnSql);
        $statement->execute(['schema' => $this->databaseName]);

        $excluded = array_fill_keys(array_map('strtolower', $this->excludedTables), true);
        $schema = [];

        while ($row = $statement->fetch()) {
            $tableName = (string) $row['TABLE_NAME'];
            if (isset($excluded[strtolower($tableName)])) {
                continue;
            }

            if (!isset($schema[$tableName])) {
                $schema[$tableName] = [
                    'name' => $tableName,
                    'columns' => [],
                    'foreign_keys' => [],
                ];
            }

            $schema[$tableName]['columns'][] = [
                'name' => (string) $row['COLUMN_NAME'],
                'type' => (string) $row['COLUMN_TYPE'],
                'nullable' => (string) $row['IS_NULLABLE'] === 'YES',
                'key' => (string) $row['COLUMN_KEY'],
                'default' => $row['COLUMN_DEFAULT'],
                'extra' => (string) $row['EXTRA'],
            ];
        }

        $foreignKeySql = <<<'SQL'
            SELECT
                TABLE_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = :schema
              AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY TABLE_NAME, ORDINAL_POSITION
        SQL;

        $foreignKeyStatement = $this->pdo->prepare($foreignKeySql);
        $foreignKeyStatement->execute(['schema' => $this->databaseName]);

        while ($row = $foreignKeyStatement->fetch()) {
            $tableName = (string) $row['TABLE_NAME'];
            $referencedTable = (string) $row['REFERENCED_TABLE_NAME'];

            if (!isset($schema[$tableName]) || isset($excluded[strtolower($referencedTable)])) {
                continue;
            }

            $schema[$tableName]['foreign_keys'][] = [
                'column' => (string) $row['COLUMN_NAME'],
                'referenced_table' => $referencedTable,
                'referenced_column' => (string) $row['REFERENCED_COLUMN_NAME'],
            ];
        }

        ksort($schema, SORT_NATURAL | SORT_FLAG_CASE);
        return $schema;
    }

    /** @param array<string, array<string, mixed>> $schema */
    public function toPrompt(array $schema): string
    {
        $lines = [
            'Database engine: MySQL',
            'Available schema:',
        ];

        foreach ($schema as $table) {
            $lines[] = sprintf('TABLE `%s`', $table['name']);

            foreach ($table['columns'] as $column) {
                $attributes = [];
                if ($column['key'] === 'PRI') {
                    $attributes[] = 'PRIMARY KEY';
                } elseif ($column['key'] === 'UNI') {
                    $attributes[] = 'UNIQUE';
                }

                $attributes[] = $column['nullable'] ? 'NULL' : 'NOT NULL';

                $lines[] = sprintf(
                    '- `%s` %s %s',
                    $column['name'],
                    strtoupper((string) $column['type']),
                    implode(' ', $attributes)
                );
            }

            foreach ($table['foreign_keys'] as $foreignKey) {
                $lines[] = sprintf(
                    '- FOREIGN KEY `%s` REFERENCES `%s`(`%s`)',
                    $foreignKey['column'],
                    $foreignKey['referenced_table'],
                    $foreignKey['referenced_column']
                );
            }

            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }
}
