<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SqlValidationException;

/**
 * Conservative validator for a deliberately limited reporting-SQL subset.
 *
 * Supported: one SELECT, joins, filters, aggregates, GROUP BY, HAVING,
 * ORDER BY and a numeric LIMIT.
 * Blocked by design: CTEs, UNION, nested SELECTs, comments, variables,
 * cross-database access, file functions and administrative statements.
 */
final class SqlValidator
{
    /** @param list<string> $excludedTables */
    public function __construct(private array $excludedTables = [])
    {
    }

    /**
     * @param array<string, array<string, mixed>> $schema
     * @throws SqlValidationException
     */
    public function validate(string $sql, array $schema): string
    {
        $sql = $this->normalize($sql);
        $masked = $this->maskQuotedStrings($sql);

        if (preg_match('/^\s*SELECT\b/i', $masked) !== 1) {
            throw new SqlValidationException('Only SELECT queries are allowed.');
        }

        preg_match_all('/\bSELECT\b/i', $masked, $selectMatches);
        if (count($selectMatches[0]) !== 1) {
            throw new SqlValidationException('Subqueries and multiple SELECT clauses are not allowed.');
        }

        if (str_contains($masked, ';')) {
            throw new SqlValidationException('Multiple SQL statements are not allowed.');
        }

        if (preg_match('/(--|#|\/\*|\*\/)/', $sql) === 1) {
            throw new SqlValidationException('SQL comments are not allowed.');
        }

        if (preg_match('/(?:@|:=)/', $masked) === 1) {
            throw new SqlValidationException('SQL variables are not allowed.');
        }

        $forbidden = [
            'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'CREATE',
            'REPLACE', 'RENAME', 'GRANT', 'REVOKE', 'CALL', 'DO', 'HANDLER',
            'LOAD', 'LOCK', 'UNLOCK', 'SET', 'USE', 'SHOW', 'DESCRIBE',
            'EXPLAIN', 'ANALYZE', 'OPTIMIZE', 'REPAIR', 'KILL', 'UNION', 'WITH',
            'INTO', 'PROCEDURE', 'OUTFILE', 'DUMPFILE', 'STRAIGHT_JOIN', 'NATURAL',
            'CURRENT_USER', 'SESSION_USER', 'SYSTEM_USER', 'CURRENT_ROLE',
        ];

        if (preg_match('/\b(?:' . implode('|', $forbidden) . ')\b/i', $masked, $match) === 1) {
            throw new SqlValidationException(sprintf('The SQL keyword %s is not allowed.', strtoupper($match[0])));
        }

        $dangerousFunctions = [
            'LOAD_FILE', 'SLEEP', 'BENCHMARK', 'GET_LOCK', 'RELEASE_LOCK',
            'IS_FREE_LOCK', 'IS_USED_LOCK', 'MASTER_POS_WAIT', 'UUID_SHORT',
        ];

        if (preg_match('/\b(?:' . implode('|', $dangerousFunctions) . ')\s*\(/i', $masked) === 1) {
            throw new SqlValidationException('A restricted MySQL function was detected.');
        }

        $this->validateFunctions($masked);

        if (preg_match('/\bFOR\s+UPDATE\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $masked) === 1) {
            throw new SqlValidationException('Locking reads are not allowed.');
        }

        if (preg_match('/\b(?:information_schema|performance_schema|mysql|sys)\s*\./i', $masked) === 1) {
            throw new SqlValidationException('System schemas cannot be queried.');
        }

        if (preg_match('/\b(?:FROM|JOIN)\s+`?[A-Za-z_][A-Za-z0-9_]*`?\s*\./i', $masked) === 1) {
            throw new SqlValidationException('Cross-database table references are not allowed.');
        }

        if (preg_match('/\bFROM\s+\(/i', $masked) === 1 || preg_match('/\bJOIN\s+\(/i', $masked) === 1) {
            throw new SqlValidationException('Derived tables and subqueries are not allowed.');
        }

        $tableMap = $this->buildTableMap($schema);
        $aliases = $this->validateTablesAndBuildAliases($masked, $tableMap);
        $this->validateQualifiedColumns($masked, $aliases, $tableMap);
        $this->validateLimit($masked);

        return $sql;
    }

    private function normalize(string $sql): string
    {
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', trim($sql)) ?? trim($sql);

        if ($sql === '') {
            throw new SqlValidationException('The generated SQL query is empty.');
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $sql) === 1) {
            throw new SqlValidationException('The generated SQL contains invalid control characters.');
        }

        // One optional final semicolon is harmless; all remaining semicolons are rejected later.
        $sql = preg_replace('/;\s*$/', '', $sql, 1) ?? $sql;
        return trim($sql);
    }

    /**
     * Replace quoted string contents with spaces while keeping SQL positions and
     * backtick identifiers intact. This avoids treating keywords inside values as SQL.
     */
    private function maskQuotedStrings(string $sql): string
    {
        $length = strlen($sql);
        $masked = $sql;
        $quote = null;

        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];

            if ($quote === null) {
                if ($character === "'" || $character === '"') {
                    $quote = $character;
                    $masked[$index] = ' ';
                }
                continue;
            }

            $masked[$index] = ' ';

            if ($character === '\\') {
                if ($index + 1 < $length) {
                    $index++;
                    $masked[$index] = ' ';
                }
                continue;
            }

            if ($character === $quote) {
                // MySQL also permits doubled quote escaping: 'It''s'.
                if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                    $index++;
                    $masked[$index] = ' ';
                    continue;
                }

                $quote = null;
            }
        }

        if ($quote !== null) {
            throw new SqlValidationException('The generated SQL contains an unterminated string.');
        }

        return $masked;
    }

    /**
     * @param array<string, array<string, mixed>> $schema
     * @return array<string, array{name:string,columns:array<string, true>}>
     */
    private function buildTableMap(array $schema): array
    {
        $tableMap = [];
        $excluded = array_fill_keys(array_map('strtolower', $this->excludedTables), true);

        foreach ($schema as $table) {
            $tableName = (string) ($table['name'] ?? '');
            if ($tableName === '' || isset($excluded[strtolower($tableName)])) {
                continue;
            }

            $columns = [];
            foreach (($table['columns'] ?? []) as $column) {
                if (is_array($column) && isset($column['name'])) {
                    $columns[strtolower((string) $column['name'])] = true;
                }
            }

            $tableMap[strtolower($tableName)] = [
                'name' => $tableName,
                'columns' => $columns,
            ];
        }

        if ($tableMap === []) {
            throw new SqlValidationException('No allowed database tables are available.');
        }

        return $tableMap;
    }

    /**
     * @param array<string, array{name:string,columns:array<string, true>}> $tableMap
     * @return array<string, string> alias/table-name => lowercase table name
     */
    private function validateTablesAndBuildAliases(string $maskedSql, array $tableMap): array
    {
        $pattern = '/\b(?:FROM|JOIN)\s+(`?[A-Za-z_][A-Za-z0-9_]*`?)(?:\s+(?:AS\s+)?(`?[A-Za-z_][A-Za-z0-9_]*`?))?/i';
        preg_match_all($pattern, $maskedSql, $matches, PREG_SET_ORDER);

        preg_match_all('/\b(?:FROM|JOIN)\b/i', $maskedSql, $tableKeywords);
        if ($matches === [] || count($matches) !== count($tableKeywords[0])) {
            throw new SqlValidationException('Every data source must be a direct allowed table.');
        }

        $reservedAliases = array_fill_keys([
            'where', 'left', 'right', 'inner', 'outer', 'cross', 'join', 'on',
            'group', 'order', 'having', 'limit', 'offset', 'and', 'or', 'using',
        ], true);

        $aliases = [];
        foreach ($matches as $match) {
            $tableName = strtolower($this->unquoteIdentifier($match[1]));
            if (!isset($tableMap[$tableName])) {
                throw new SqlValidationException(sprintf('Table "%s" is not in the connected schema.', $tableName));
            }

            $alias = isset($match[2]) ? strtolower($this->unquoteIdentifier($match[2])) : $tableName;
            if (isset($reservedAliases[$alias])) {
                $alias = $tableName;
            }

            if (isset($aliases[$alias]) && $aliases[$alias] !== $tableName) {
                throw new SqlValidationException(sprintf('SQL alias "%s" is used for multiple tables.', $alias));
            }

            $aliases[$alias] = $tableName;
            $aliases[$tableName] = $tableName;
        }

        // Comma joins are intentionally blocked because they can hide unvalidated tables.
        if (preg_match('/\bFROM\s+`?[A-Za-z_][A-Za-z0-9_]*`?(?:\s+(?:AS\s+)?`?[A-Za-z_][A-Za-z0-9_]*`?)?\s*,/i', $maskedSql) === 1) {
            throw new SqlValidationException('Comma joins are not allowed. Use explicit JOIN syntax.');
        }

        return $aliases;
    }

    /**
     * @param array<string, string> $aliases
     * @param array<string, array{name:string,columns:array<string, true>}> $tableMap
     */
    private function validateQualifiedColumns(string $maskedSql, array $aliases, array $tableMap): void
    {
        $pattern = '/(`?[A-Za-z_][A-Za-z0-9_]*`?)\s*\.\s*(`?[A-Za-z_][A-Za-z0-9_]*`?|\*)/';
        preg_match_all($pattern, $maskedSql, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $qualifier = strtolower($this->unquoteIdentifier($match[1]));
            $column = strtolower($this->unquoteIdentifier($match[2]));

            if (!isset($aliases[$qualifier])) {
                throw new SqlValidationException(sprintf('Unknown table alias "%s".', $qualifier));
            }

            if ($column === '*') {
                continue;
            }

            $tableName = $aliases[$qualifier];
            if (!isset($tableMap[$tableName]['columns'][$column])) {
                throw new SqlValidationException(sprintf(
                    'Column "%s.%s" does not exist in the connected schema.',
                    $qualifier,
                    $column
                ));
            }
        }
    }


    private function validateFunctions(string $maskedSql): void
    {
        $safeFunctions = array_fill_keys([
            'abs', 'avg', 'cast', 'ceil', 'ceiling', 'char_length', 'coalesce',
            'concat', 'concat_ws', 'convert', 'count', 'curdate', 'date',
            'date_add', 'date_format', 'date_sub', 'datediff', 'day', 'dayname',
            'extract', 'floor', 'greatest', 'if', 'ifnull', 'in', 'json_extract',
            'json_unquote', 'least', 'left', 'length', 'lower', 'ltrim', 'max',
            'min', 'mod', 'month', 'monthname', 'nullif', 'regexp_like', 'replace',
            'right', 'round', 'rtrim', 'substring', 'sum', 'time', 'timestampdiff',
            'trim', 'upper', 'year',
        ], true);

        preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $maskedSql, $matches);
        foreach ($matches[1] as $functionName) {
            $normalized = strtolower((string) $functionName);
            if (!isset($safeFunctions[$normalized])) {
                throw new SqlValidationException(sprintf(
                    'SQL function "%s" is not allowed by the reporting query policy.',
                    $functionName
                ));
            }
        }
    }

    private function validateLimit(string $maskedSql): void
    {
        if (preg_match('/\bLIMIT\b/i', $maskedSql) !== 1) {
            return;
        }

        if (preg_match('/\bLIMIT\s+\d+(?:\s*,\s*\d+|\s+OFFSET\s+\d+)?\s*$/i', $maskedSql) !== 1) {
            throw new SqlValidationException('LIMIT must use fixed numeric values and appear at the end of the query.');
        }
    }

    private function unquoteIdentifier(string $identifier): string
    {
        return trim($identifier, "` \t\n\r\0\x0B");
    }
}
