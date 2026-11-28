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

/** Split a VALUES clause into top-level (...) tuples, respecting quotes. */
function pos_sql_split_values(string $sql): ?array
{
    $tuples = [];
    $current = '';
    $depth = 0;
    $quote = '';
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';
        if ($quote !== '') {
            $current .= $char;
            if ($char === '\\' && $next !== '') {
                $current .= $next;
                $i++;
                continue;
            }
            if ($char === $quote) {
                if ($next === $quote) {
                    $current .= $next;
                    $i++;
                    continue;
                }
                $quote = '';
            }
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $current .= $char;
            continue;
        }
        if ($char === '(') {
            $depth++;
            if ($depth === 1) {
                $current = '';
            } else {
                $current .= $char;
            }
            continue;
        }
        if ($char === ')') {
            $depth--;
            if ($depth === 0) {
                $tuples[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
            continue;
        }
        if ($depth > 0) {
            $current .= $char;
        }
    }
    if ($depth !== 0 || $quote !== '' || $tuples === []) {
        return null;
    }
    return $tuples;
}

/** Split one tuple into values, respecting quotes and nested parentheses. */
function pos_sql_split_tuple(string $tuple): ?array
{
    $values = [];
    $current = '';
    $depth = 0;
    $quote = '';
    $length = strlen($tuple);
    for ($i = 0; $i < $length; $i++) {
        $char = $tuple[$i];
        $next = $i + 1 < $length ? $tuple[$i + 1] : '';
        if ($quote !== '') {
            $current .= $char;
            if ($char === '\\' && $next !== '') {
                $current .= $next;
                $i++;
                continue;
            }
            if ($char === $quote) {
                if ($next === $quote) {
                    $current .= $next;
                    $i++;
                    continue;
                }
                $quote = '';
            }
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $current .= $char;
            continue;
        }
        if ($char === '(') {
            $depth++;
            $current .= $char;
            continue;
        }
        if ($char === ')') {
            $depth--;
            $current .= $char;
            continue;
        }
        if ($char === ',' && $depth === 0) {
            $values[] = $current;
            $current = '';
            continue;
        }
        $current .= $char;
    }
    if ($quote !== '' || $depth !== 0) {
        return null;
    }
    $values[] = $current;
    return $values;
}

function pos_sql_unquote(string $value): string
{
    $value = trim($value);
    if ($value === 'NULL') {
        return '';
    }
    if (strlen($value) >= 2 && in_array($value[0], ["'", '"', '`'], true)) {
        $quote = $value[0];
        $inner = substr($value, 1, -1);
        $inner = str_replace($quote . $quote, $quote, $inner);
        $inner = str_replace("\\" . $quote, $quote, $inner);
        $inner = str_replace("\\\\", "\\", $inner);
        return $inner;
    }
    return $value;
}

/** Backups exported by this app only contain NULL or single-quoted literal values. */
function pos_sql_is_literal_value(string $value): bool
{
    $value = trim($value);
    if (strcasecmp($value, 'NULL') === 0) {
        return true;
    }
    $length = strlen($value);
    if ($length < 2 || $value[0] !== "'" || $value[$length - 1] !== "'") {
        return false;
    }
    for ($i = 1; $i < $length - 1; $i++) {
        if ($value[$i] === '\\') {
            if ($i === $length - 2) {
                return false;
            }
            $i++;
            continue;
        }
        if ($value[$i] === "'") {
            if ($i + 1 < $length - 1 && $value[$i + 1] === "'") {
                $i++;
                continue;
            }
            return false;
        }
    }
    return true;
}

/** Reject INSERT ... SELECT, functions, and other non-literal value expressions. */
function pos_backup_has_literal_inserts(array $statements): bool
{
    foreach ($statements as $statement) {
        if (!preg_match(
            '/^INSERT INTO `([A-Za-z_]+)`\s*\(([^)]*)\)\s+VALUES\s+(.*)$/is',
            $statement,
            $matches
        )) {
            continue;
        }
        $columnCount = count(explode(',', $matches[2]));
        $tuples = pos_sql_split_values($matches[3]);
        if ($tuples === null) {
            return false;
        }
        foreach ($tuples as $tuple) {
            $values = pos_sql_split_tuple($tuple);
            if ($values === null || count($values) !== $columnCount) {
                return false;
            }
            foreach ($values as $value) {
                if (!pos_sql_is_literal_value($value)) {
                    return false;
                }
            }
        }
    }
    return true;
}

/** Count admin rows encoded in users INSERT statements. Null means unparsable. */
function pos_backup_admin_counts(array $statements): ?array
{
    $admins = 0;
    $active = 0;
    foreach ($statements as $statement) {
        if (!preg_match(
            '/^INSERT INTO `users`\s*\(([^)]*)\)\s+VALUES\s+(.*)$/is',
            $statement,
            $matches
        )) {
            continue;
        }
        $columns = array_map(static function (string $column): string {
            return strtolower(trim(trim($column), '` '));
        }, explode(',', $matches[1]));
        $roleIndex = array_search('role', $columns, true);
        $statusIndex = array_search('status', $columns, true);
        if ($roleIndex === false || $statusIndex === false) {
            return null;
        }
        $tuples = pos_sql_split_values($matches[2]);
        if ($tuples === null) {
            return null;
        }
        foreach ($tuples as $tuple) {
            $values = pos_sql_split_tuple($tuple);
            if ($values === null || count($values) !== count($columns)) {
                return null;
            }
            $role = strtolower(pos_sql_unquote($values[$roleIndex]));
            $status = strtolower(pos_sql_unquote($values[$statusIndex]));
            if ($role === 'admin') {
                $admins++;
                if ($status === 'active') {
                    $active++;
                }
            }
        }
    }
    return ['admins' => $admins, 'active' => $active];
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
        if (preg_match('/^INSERT INTO `([A-Za-z_]+)`\s*\([^)]*\)\s+VALUES\s+/is', $statement, $matches)) {
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
    if (!pos_backup_has_literal_inserts($statements)) {
        return ['ok' => false, 'error' => 'Backup contains unsupported value expressions.'];
    }
    $admins = pos_backup_admin_counts($statements);
    if ($admins === null) {
        return ['ok' => false, 'error' => 'Backup user data could not be verified.'];
    }
    if ($admins['admins'] !== 1 || $admins['active'] !== 1) {
        return ['ok' => false, 'error' => 'Backup must contain exactly one active website administrator.'];
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
