<?php
declare(strict_types=1);

/** Client-scoped backup helpers: only the signed-in user's sales and sale items. */

const CLIENT_BACKUP_FORMAT = 'simple-pos-client-sales';
const CLIENT_BACKUP_VERSION = 1;
const CLIENT_BACKUP_MAX_SALES = 10000;
const CLIENT_BACKUP_MAX_ITEMS = 100000;

function client_backup_store_slug(string $storeName): string
{
    $slug = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', trim($storeName));
    if (!is_string($slug)) {
        return 'client_sales';
    }
    $slug = trim($slug, '_');
    if ($slug === '') {
        return 'client_sales';
    }
    return function_exists('mb_substr') ? mb_substr($slug, 0, 80) : substr($slug, 0, 80);
}

function client_backup_filename(string $suffix = '', string $storeName = ''): string
{
    if ($storeName === '' && function_exists('get_setting')) {
        $storeName = get_setting('store_name', 'My Store');
    }
    $name = client_backup_store_slug($storeName);
    if ($suffix !== '') {
        $name .= '_' . $suffix;
    }
    return $name . '_' . date('Ymd_His') . '.json';
}

function client_backup_decimal($value): ?string
{
    if (is_int($value) || is_float($value)) {
        $value = (string)$value;
    }
    if (!is_string($value) || !preg_match('/^-?\d{1,12}(?:\.\d{1,2})?$/', $value)) {
        return null;
    }
    return (float)$value < 0 ? null : $value;
}

function client_backup_integer($value): ?int
{
    if (is_int($value)) {
        return $value >= 0 ? $value : null;
    }
    if (is_string($value) && preg_match('/^\d{1,10}$/', $value)) {
        return (int)$value;
    }
    return null;
}

/** @return array<int,array<string,mixed>> */
function client_backup_fetch(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, receipt_number, total_amount, cash_received, change_amount, created_at
         FROM sales WHERE user_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$userId]);

    $itemStmt = $pdo->prepare(
        'SELECT product_id, product_name, quantity, unit, unit_price, subtotal
         FROM sale_items WHERE sale_id = ? ORDER BY id ASC'
    );

    $sales = [];
    foreach ($stmt->fetchAll() as $row) {
        $itemStmt->execute([(int)$row['id']]);
        $items = [];
        foreach ($itemStmt->fetchAll() as $item) {
            $items[] = [
                'product_id' => (int)$item['product_id'],
                'product_name' => (string)$item['product_name'],
                'quantity' => (int)$item['quantity'],
                'unit' => (string)$item['unit'],
                'unit_price' => number_format((float)$item['unit_price'], 2, '.', ''),
                'subtotal' => number_format((float)$item['subtotal'], 2, '.', ''),
            ];
        }
        $sales[] = [
            'receipt_number' => (string)$row['receipt_number'],
            'total_amount' => number_format((float)$row['total_amount'], 2, '.', ''),
            'cash_received' => number_format((float)$row['cash_received'], 2, '.', ''),
            'change_amount' => number_format((float)$row['change_amount'], 2, '.', ''),
            'created_at' => (string)$row['created_at'],
            'items' => $items,
        ];
    }
    return $sales;
}

function client_backup_generate(PDO $pdo, int $userId): string
{
    $payload = [
        'format' => CLIENT_BACKUP_FORMAT,
        'version' => CLIENT_BACKUP_VERSION,
        'generated_at' => date('Y-m-d H:i:s'),
        'user_id' => $userId,
        'sales' => client_backup_fetch($pdo, $userId),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Unable to encode the client backup.');
    }
    return $json;
}

/** Strictly validate a client backup. The embedded user_id is never trusted for restore. */
function client_backup_validate(string $json, int $userId): array
{
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'This file is not a Simple POS client backup.'];
    }

    $allowedTop = ['format', 'version', 'generated_at', 'user_id', 'sales'];
    if (array_diff(array_keys($data), $allowedTop) !== []) {
        return ['ok' => false, 'error' => 'Backup contains unexpected fields.'];
    }
    if (($data['format'] ?? '') !== CLIENT_BACKUP_FORMAT || ($data['version'] ?? null) !== CLIENT_BACKUP_VERSION) {
        return ['ok' => false, 'error' => 'This backup format is not supported.'];
    }
    if (!is_array($data['sales'] ?? null) || count($data['sales']) > CLIENT_BACKUP_MAX_SALES) {
        return ['ok' => false, 'error' => 'Backup sales data is invalid or too large.'];
    }

    $sales = [];
    $itemCount = 0;
    foreach ($data['sales'] as $sale) {
        if (!is_array($sale)) {
            return ['ok' => false, 'error' => 'Backup contains an invalid sale.'];
        }
        $allowedSale = ['receipt_number', 'total_amount', 'cash_received', 'change_amount', 'created_at', 'items'];
        if (array_diff(array_keys($sale), $allowedSale) !== []) {
            return ['ok' => false, 'error' => 'Backup contains unexpected sale fields.'];
        }
        foreach ($allowedSale as $field) {
            if (!array_key_exists($field, $sale)) {
                return ['ok' => false, 'error' => 'Backup sale data is incomplete.'];
            }
        }

        $receipt = $sale['receipt_number'];
        if (!is_string($receipt) || $receipt === '' || strlen($receipt) > 50) {
            return ['ok' => false, 'error' => 'Backup contains an invalid receipt number.'];
        }
        $total = client_backup_decimal($sale['total_amount']);
        $cash = client_backup_decimal($sale['cash_received']);
        $change = client_backup_decimal($sale['change_amount']);
        if ($total === null || $cash === null || $change === null) {
            return ['ok' => false, 'error' => 'Backup contains an invalid sale amount.'];
        }
        $createdAt = $sale['created_at'];
        if (!is_string($createdAt) || strlen($createdAt) > 19 || strtotime($createdAt) === false) {
            return ['ok' => false, 'error' => 'Backup contains an invalid sale date.'];
        }
        if (!is_array($sale['items']) || count($sale['items']) > 1000) {
            return ['ok' => false, 'error' => 'Backup contains invalid sale items.'];
        }

        $items = [];
        foreach ($sale['items'] as $item) {
            if (!is_array($item)) {
                return ['ok' => false, 'error' => 'Backup contains an invalid sale item.'];
            }
            $allowedItem = ['product_id', 'product_name', 'quantity', 'unit', 'unit_price', 'subtotal'];
            if (array_diff(array_keys($item), $allowedItem) !== []) {
                return ['ok' => false, 'error' => 'Backup contains unexpected sale item fields.'];
            }
            foreach ($allowedItem as $field) {
                if (!array_key_exists($field, $item)) {
                    return ['ok' => false, 'error' => 'Backup sale item data is incomplete.'];
                }
            }
            $productId = client_backup_integer($item['product_id']);
            $quantity = client_backup_integer($item['quantity']);
            $unitPrice = client_backup_decimal($item['unit_price']);
            $subtotal = client_backup_decimal($item['subtotal']);
            $name = $item['product_name'];
            $unit = $item['unit'];
            if ($productId === null || $productId <= 0 || $quantity === null || $unitPrice === null || $subtotal === null) {
                return ['ok' => false, 'error' => 'Backup contains invalid sale item values.'];
            }
            if (!is_string($name) || $name === '' || strlen($name) > 255) {
                return ['ok' => false, 'error' => 'Backup contains an invalid product name.'];
            }
            if (!is_string($unit) || $unit === '' || strlen($unit) > 50) {
                return ['ok' => false, 'error' => 'Backup contains an invalid unit.'];
            }
            $itemCount++;
            if ($itemCount > CLIENT_BACKUP_MAX_ITEMS) {
                return ['ok' => false, 'error' => 'Backup contains too many sale items.'];
            }
            $items[] = [
                'product_id' => $productId,
                'product_name' => $name,
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
            ];
        }

        $sales[] = [
            'receipt_number' => $receipt,
            'total_amount' => $total,
            'cash_received' => $cash,
            'change_amount' => $change,
            'created_at' => $createdAt,
            'items' => $items,
        ];
    }

    return ['ok' => true, 'sales' => $sales, 'user_id' => (int)($data['user_id'] ?? $userId)];
}

/** Replace only the signed-in user's sales and sale items, inside one transaction. */
function client_backup_restore(PDO $pdo, array $validated, int $userId): array
{
    $sales = $validated['sales'];
    $pdo->beginTransaction();
    try {
        $idStmt = $pdo->prepare('SELECT id FROM sales WHERE user_id = ? ORDER BY id ASC');
        $idStmt->execute([$userId]);
        $saleIds = array_map('intval', $idStmt->fetchAll(PDO::FETCH_COLUMN));
        if ($saleIds !== []) {
            $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
            $deleteItems = $pdo->prepare('DELETE FROM sale_items WHERE sale_id IN (' . $placeholders . ')');
            $deleteItems->execute($saleIds);
        }

        $deleteSales = $pdo->prepare('DELETE FROM sales WHERE user_id = ?');
        $deleteSales->execute([$userId]);

        $insertSale = $pdo->prepare(
            'INSERT INTO sales (receipt_number, total_amount, cash_received, change_amount, user_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insertItem = $pdo->prepare(
            'INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit, unit_price, subtotal)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $itemCount = 0;
        foreach ($sales as $sale) {
            $insertSale->execute([
                $sale['receipt_number'],
                $sale['total_amount'],
                $sale['cash_received'],
                $sale['change_amount'],
                $userId,
                $sale['created_at'],
            ]);
            $saleId = (int)$pdo->lastInsertId();
            foreach ($sale['items'] as $item) {
                $insertItem->execute([
                    $saleId,
                    $item['product_id'],
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit'],
                    $item['unit_price'],
                    $item['subtotal'],
                ]);
                $itemCount++;
            }
        }

        $check = $pdo->prepare('SELECT COUNT(*) FROM sales WHERE user_id = ?');
        $check->execute([$userId]);
        if ((int)$check->fetchColumn() !== count($sales)) {
            throw new RuntimeException('Client restore verification failed.');
        }

        $pdo->commit();
        return ['sales' => count($sales), 'items' => $itemCount];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}
