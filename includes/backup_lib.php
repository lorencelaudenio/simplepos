<?php
declare(strict_types=1);

/** Native MySQL backup (SQL dump) and strictly validated restore helpers. */

function pos_backup_tables(): array
{
    return ['users', 'products', 'sales', 'sale_items', 'stock_movements', 'settings'];
}

function pos_backup_filename(string $prefix = 'pos_backup'): string
{
    return $prefix . '_' . date('Ymd_His') . '.sql';
}

function generate_sql_dump(PDO $pdo): string
{
    $lines = [];
    $lines[] = '-- SIMPLE POS BACKUP';
    $lines[] = '-- Generated: ' . date('Y-m-d H:i:s');
    $lines[] = '-- Source: ' . (defined('DB_NAME') ? DB_NAME : 'pos');
    $lines[] = 'SET NAMES utf8mb4;';
    $lines[] = 'SET FOREIGN_KEY_CHECKS=0;';
    $lines[] = '';

    foreach (pos_backup_tables() as $table) {
        $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
        if (!$create || empty($create[1])) {
            throw new RuntimeException('Unable to read the structure of table ' . $table . '.');
        }
        $lines[] = (string)$create[1] . ';';
        $lines[] = '';

        $rows = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            continue;
        }
        $columns = array_keys($rows[0]);
        $columnSql = '`' . implode('`, `', $columns) . '`';
        $chunks = array_chunk($rows, 100);
        foreach ($chunks as $chunk) {
            $tuples = [];
            foreach ($chunk as $row) {
                $values = [];
                foreach ($columns as $column) {
                    $value = $row[$column];
                    $values[] = $value === null ? 'NULL' : $pdo->quote((string)$value);
                }
                $tuples[] = '(' . implode(', ', $values) . ')';
            }
            $lines[] = 'INSERT INTO `' . $table . '` (' . $columnSql . ') VALUES';
            $lines[] = implode(",\n", $tuples) . ';';
            $lines[] = '';
        }
    }
    $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';
    $lines[] = '';
    return implode("\n", $lines);
}

/** Splits SQL into statements, honouring quotes, backslash escapes and comments. */
function pos_sql_parse(string $sql): array
{
    $statements = [];
    $buffer = '';
    $quote = '';
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($quote === '') {
            if ($char === '-' && $next === '-' && ($i === 0 || preg_match('/\s/', $sql[$i - 1]))) {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($char === '/' && $next === '*') {
                $i += 2;
                while ($i + 1 < $length && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $i++;
                }
                $i++;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                $clean = trim($buffer);
                if ($clean !== '') {
                    $statements[] = $clean;
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
            continue;
        }

        $buffer .= $char;
        if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
            $buffer .= $sql[++$i];
            continue;
        }
        if ($char === $quote) {
            // Doubled quote escapes are kept intact.
            if ($next === $quote) {
                $buffer .= $sql[++$i];
                continue;
            }
            $quote = '';
        }
    }
    $clean = trim($buffer);
    if ($clean !== '') {
        $statements[] = $clean;
    }
    return $statements;
}

/** Only accepts dumps produced in the expected Simple POS format. */
function pos_validate_backup(string $sql): array
{
    if (!str_contains($sql, 'SIMPLE POS BACKUP')) {
        return ['ok' => false, 'error' => 'This file is not a Simple POS backup.'];
    }
    $statements = pos_sql_parse($sql);
    $allowed = array_flip(pos_backup_tables());
    $created = [];
    $hasSet = false;
    foreach ($statements as $statement) {
        if (preg_match('/^SET FOREIGN_KEY_CHECKS\s*=\s*[01]$/i', $statement)) {
            $hasSet = true;
            continue;
        }
        if (preg_match('/^SET NAMES\s+[A-Za-z0-9_]+$/i', $statement)) {
            continue;
        }
        if (preg_match('/^DROP TABLE IF EXISTS `([A-Za-z_]+)`$/i', $statement, $matches)) {
            if (!isset($allowed[$matches[1]])) {
                return ['ok' => false, 'error' => 'Backup contains an unexpected table.'];
            }
            continue;
        }
        if (preg_match('/^CREATE TABLE `([A-Za-z_]+)`\s*\(/i', $statement, $matches)) {
            if (!isset($allowed[$matches[1]])) {
                return ['ok' => false, 'error' => 'Backup contains an unexpected table.'];
            }
            $created[$matches[1]] = true;
            continue;
        }
        if (preg_match('/^INSERT INTO `([A-Za-z_]+)`\s*\(/i', $statement, $matches)) {
            if (!isset($allowed[$matches[1]])) {
                return ['ok' => false, 'error' => 'Backup contains data for an unexpected table.'];
            }
            continue;
        }
        return ['ok' => false, 'error' => 'Backup contains an unsupported SQL statement and was rejected.'];
    }
    if (!$hasSet || count($created) !== count($allowed)) {
        return ['ok' => false, 'error' => 'Backup structure is incomplete or invalid.'];
    }
    return ['ok' => true, 'statements' => $statements];
}

function pos_restore(PDO $pdo, array $statements): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach ($statements as $statement) {
            if (preg_match('/^SET FOREIGN_KEY_CHECKS\s*=/i', $statement)) {
                continue;
            }
            $pdo->exec($statement);
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
