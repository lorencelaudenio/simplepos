<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_bootstrap.php';

function format_product(array $p): array
{
    $stock = (int)$p['stock_quantity'];
    $reorder = (int)$p['reorder_level'];
    return [
        'id' => (int)$p['id'],
        'product_name' => (string)$p['product_name'],
        'barcode' => $p['barcode'] === null ? '' : (string)$p['barcode'],
        'selling_price' => (float)$p['selling_price'],
        'unit' => (string)$p['unit'],
        'stock_quantity' => $stock,
        'reorder_level' => $reorder,
        'status' => (string)$p['status'],
        'low_stock' => $stock <= $reorder,
        'created_at' => (string)$p['created_at'],
        'updated_at' => (string)$p['updated_at'],
    ];
}

$action = $_GET['action'] ?? '';

api_route(function () use ($action) {
    $pdo = db();

    if ($action === 'list' || $action === 'search') {
        $search = isset($_GET['q']) ? trim((string)$_GET['q']) : trim((string)($_GET['search'] ?? ''));
        $status = $action === 'search' ? 'active' : (string)($_GET['status'] ?? 'active');
        $lowOnly = bval($_GET, 'low_stock');
        $where = [];
        $params = [];
        if ($status !== 'all') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where[] = '(product_name LIKE ? OR barcode LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($lowOnly) {
            $where[] = 'stock_quantity <= reorder_level';
        }
        $sql = 'SELECT * FROM products';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        if ($action === 'search') {
            // Exact barcode matches first, then alphabetical.
            $sql .= ' ORDER BY (barcode = ?) DESC, product_name ASC LIMIT 25';
            $params[] = $search;
        } else {
            $sql .= ' ORDER BY id DESC LIMIT 500';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        json_ok(['products' => array_map('format_product', $rows)]);
    }

    if ($action === 'get') {
        $id = ival($_GET, 'id');
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            fail('Product not found.', 404);
        }
        json_ok(['product' => format_product($row)]);
    }

    if ($action === 'save') {
        $body = request_body();
        $id = ival($body, 'id');
        $name = sval($body, 'product_name');
        $barcode = sval($body, 'barcode');
        $unit = sval($body, 'unit');
        if (!is_numeric($body['selling_price'] ?? null)
            || filter_var($body['stock_quantity'] ?? null, FILTER_VALIDATE_INT) === false
            || filter_var($body['reorder_level'] ?? null, FILTER_VALIDATE_INT) === false) {
            fail('Price, stock, and reorder level must be valid numbers.');
        }
        $price = round(fval($body, 'selling_price'), 2);
        $stock = ival($body, 'stock_quantity');
        $reorder = ival($body, 'reorder_level');

        if ($name === '' || mb_strlen($name) > 255) {
            fail('Product name is required (255 characters maximum).');
        }
        if ($price < 0 || $price > 99999999) {
            fail('Selling price must be a valid non-negative amount.');
        }
        if ($unit === '' || mb_strlen($unit) > 50) {
            fail('Unit of measurement is required (50 characters maximum).');
        }
        if ($stock < 0 || $stock > 100000000) {
            fail('Stock quantity must be a non-negative whole number.');
        }
        if ($reorder < 0 || $reorder > 100000000) {
            fail('Reorder level must be a non-negative whole number.');
        }
        if ($barcode !== '' && strlen($barcode) > 100) {
            fail('Barcode must be 100 characters or fewer.');
        }
        if ($barcode !== '' && !preg_match('/^[\x20-\x7E]+$/', $barcode)) {
            fail('Barcode may only contain printable ASCII characters.');
        }
        $barcodeValue = $barcode === '' ? null : $barcode;

        $pdo->beginTransaction();
        try {
            $check = $pdo->prepare(
                'SELECT id FROM products WHERE barcode = ?' . ($id > 0 ? ' AND id <> ?' : '')
            );
            $checkParams = $barcodeValue === null ? [] : [$barcodeValue];
            if ($id > 0) {
                $checkParams[] = $id;
            }
            if ($barcodeValue === null) {
                // Multiple NULL barcodes are allowed; skip the duplicate check.
            } else {
                $check->execute($checkParams);
                if ($check->fetchColumn()) {
                    fail('Another product already uses this barcode.');
                }
            }

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE products SET product_name = ?, barcode = ?, selling_price = ?, unit = ?,
                     stock_quantity = ?, reorder_level = ? WHERE id = ?'
                );
                $stmt->execute([$name, $barcodeValue, money_val($price), $unit, $stock, $reorder, $id]);

                $current = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = ?');
                $current->execute([$id]);
                $previousStock = (int)$current->fetchColumn();
                if ($previousStock !== $stock) {
                    record_stock_movement(
                        $pdo,
                        $id,
                        'adjustment',
                        $stock - $previousStock,
                        $previousStock,
                        $stock,
                        'Stock updated through product form'
                    );
                }
                $message = 'Product updated successfully.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO products (product_name, barcode, selling_price, unit, stock_quantity, reorder_level, status)
                     VALUES (?, ?, ?, ?, ?, ?, "active")'
                );
                $stmt->execute([$name, $barcodeValue, money_val($price), $unit, $stock, $reorder]);
                $id = (int)$pdo->lastInsertId();
                if ($stock > 0) {
                    record_stock_movement($pdo, $id, 'stock_in', $stock, 0, $stock, 'Opening stock');
                }
                $message = 'Product added successfully.';
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }

        json_ok(['id' => $id, 'low_stock_count' => low_stock_count($pdo)], $message);
    }

    if ($action === 'delete') {
        $id = ival(request_body(), 'id');
        if ($id <= 0) {
            fail('Invalid product.');
        }
        $pdo->beginTransaction();
        try {
            $check = $pdo->prepare('SELECT product_name FROM products WHERE id = ? FOR UPDATE');
            $check->execute([$id]);
            $name = $check->fetchColumn();
            if (!$name) {
                fail('Product not found.', 404);
            }
            $sales = $pdo->prepare('SELECT COUNT(*) FROM sale_items WHERE product_id = ?');
            $sales->execute([$id]);
            $hasSales = (int)$sales->fetchColumn() > 0;
            $movements = $pdo->prepare('SELECT COUNT(*) FROM stock_movements WHERE product_id = ?');
            $movements->execute([$id]);
            $hasHistory = $hasSales || (int)$movements->fetchColumn() > 0;
            if ($hasHistory) {
                $stmt = $pdo->prepare('UPDATE products SET status = "archived" WHERE id = ?');
                $stmt->execute([$id]);
                $message = $hasSales
                    ? 'Product has sales history, so it was archived instead of deleted.'
                    : 'Product has stock history, so it was archived instead of deleted.';
                $archived = true;
            } else {
                $stmt = $pdo->prepare('DELETE FROM stock_movements WHERE product_id = ?');
                $stmt->execute([$id]);
                $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
                $stmt->execute([$id]);
                $message = 'Product deleted successfully.';
                $archived = false;
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        json_ok(['archived' => $archived, 'low_stock_count' => low_stock_count($pdo)], $message);
    }

    if ($action === 'restore') {
        $id = ival(request_body(), 'id');
        $stmt = $pdo->prepare('UPDATE products SET status = "active" WHERE id = ? AND status = "archived"');
        $stmt->execute([$id]);
        if ($stmt->rowCount() < 1) {
            fail('Archived product not found.', 404);
        }
        json_ok(['low_stock_count' => low_stock_count($pdo)], 'Product restored to active inventory.');
    }

    fail('Unknown product action.', 404);
});
