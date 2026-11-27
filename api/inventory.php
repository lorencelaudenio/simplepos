<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../includes/xlsx.php';

function import_header_index(array $headers, array $aliases): ?int
{
    foreach ($headers as $index => $header) {
        $normalized = strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string)$header));
        if (in_array($normalized, $aliases, true)) {
            return (int)$index;
        }
    }
    return null;
}

function stock_adjust(PDO $pdo, int $productId, int $quantity, string $notes): array
{
    if (!is_int($quantity)) {
        fail('Enter a whole number quantity.');
    }
    if ($productId <= 0 || $quantity <= 0 || $quantity > 100000000) {
        fail('Enter a valid quantity to add (whole numbers only).');
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = ? AND status = "active" FOR UPDATE');
        $stmt->execute([$productId]);
        $previous = $stmt->fetchColumn();
        if ($previous === false) {
            fail('Active product not found.', 404);
        }
        $previous = (int)$previous;
        $new = $previous + $quantity;
        $stmt = $pdo->prepare('UPDATE products SET stock_quantity = ? WHERE id = ?');
        $stmt->execute([$new, $productId]);
        record_stock_movement($pdo, $productId, 'stock_in', $quantity, $previous, $new, $notes !== '' ? $notes : 'Manual stock addition');
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return ['previous_stock' => $previous, 'added' => $quantity, 'new_stock' => $new];
}

$action = $_GET['action'] ?? '';

api_route(function () use ($action) {
    require_pos_user();
    $pdo = db();

    if ($action === 'add_stock') {
        $body = request_body();
        if (filter_var($body['quantity'] ?? null, FILTER_VALIDATE_INT) === false) {
            fail('Enter a valid whole number quantity.');
        }
        json_ok(
            stock_adjust($pdo, ival($body, 'id'), ival($body, 'quantity'), sval($body, 'notes')),
            'Stock updated successfully.'
        );
    }

    if ($action === 'movements') {
        $id = ival($_GET, 'id');
        $stmt = $pdo->prepare(
            'SELECT m.*, p.product_name FROM stock_movements m
             LEFT JOIN products p ON p.id = m.product_id
             WHERE m.product_id = ? ORDER BY m.id DESC LIMIT 50'
        );
        $stmt->execute([$id]);
        json_ok(['movements' => $stmt->fetchAll()]);
    }

    if ($action === 'low_stock') {
        $stmt = $pdo->prepare(
            'SELECT id, product_name, stock_quantity, reorder_level FROM products
             WHERE status = "active" AND stock_quantity <= reorder_level ORDER BY product_name'
        );
        $stmt->execute();
        json_ok([
            'count' => (int)($stmt->rowCount()),
            'products' => $stmt->fetchAll(),
        ]);
    }

    if ($action === 'template') {
        xlsx_send(
            'product_import_template.xlsx',
            ['Product Name', 'Barcode', 'Selling Price', 'Unit', 'Quantity in Stock', 'Reorder Level'],
            [['Sample Product', '1234567890123', '125.00', 'Piece', '20', '5']]
        );
    }

    if ($action === 'export') {
        $search = trim((string)($_GET['q'] ?? ''));
        $lowOnly = bval($_GET, 'low_stock');
        $status = (string)($_GET['status'] ?? 'active');
        $where = [];
        $params = [];
        if ($status !== 'all') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where[] = '(product_name LIKE ? OR barcode LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        if ($lowOnly) {
            $where[] = 'stock_quantity <= reorder_level';
        }
        $sql = 'SELECT * FROM products' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll() as $product) {
            $rows[] = [
                $product['product_name'],
                $product['barcode'] ?? '',
                money_val($product['selling_price']),
                $product['unit'],
                (int)$product['stock_quantity'],
                (int)$product['reorder_level'],
            ];
        }
        xlsx_send(
            'inventory_' . date('Ymd_His') . '.xlsx',
            ['Product Name', 'Barcode', 'Selling Price', 'Unit', 'Quantity in Stock', 'Reorder Level'],
            $rows
        );
    }

    if ($action === 'import_preview') {
        if (!isset($_FILES['file']) || (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            fail('Please choose an Excel (.xlsx) file to upload.');
        }
        if ((int)$_FILES['file']['size'] > 5 * 1024 * 1024) {
            fail('The Excel file must be 5 MB or smaller.');
        }
        if (strtolower(pathinfo((string)$_FILES['file']['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            fail('Only .xlsx Excel files are accepted.');
        }
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($_FILES['file']['tmp_name']);
            if (!in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'], true)) {
                fail('The uploaded file does not look like an Excel workbook.');
            }
        }
        $destination = upload_dir('imports') . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.xlsx';
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
            fail('Unable to store the uploaded file.');
        }
        try {
            $rawRows = xlsx_read_rows($destination);
        } catch (Throwable $ex) {
            @unlink($destination);
            throw $ex;
        }
        if (!$rawRows) {
            @unlink($destination);
            fail('The workbook is empty.');
        }

        $headers = array_shift($rawRows);
        $indexes = [
            'name' => import_header_index($headers, ['productname', 'name', 'product']),
            'barcode' => import_header_index($headers, ['barcode', 'barcodeid']),
            'price' => import_header_index($headers, ['sellingprice', 'price', 'unitprice']),
            'unit' => import_header_index($headers, ['unit', 'uom', 'unitofmeasurement']),
            'stock' => import_header_index($headers, ['quantityinstock', 'stock', 'quantity', 'stockquantity']),
            'reorder' => import_header_index($headers, ['reorderlevel', 'reorder', 'reorderpoint']),
        ];
        foreach (['name', 'price', 'unit', 'stock', 'reorder'] as $required) {
            if ($indexes[$required] === null) {
                @unlink($destination);
                fail('Missing required column: ' . $required . '. Download the template to see the expected format.');
            }
        }

        $cell = static function (array $row, ?int $index): string {
            return $index === null ? '' : trim((string)($row[$index] ?? ''));
        };
        $seenBarcodes = [];
        $preview = [];
        foreach ($rawRows as $offset => $raw) {
            $name = $cell($raw, $indexes['name']);
            $barcode = $cell($raw, $indexes['barcode']);
            $priceRaw = $cell($raw, $indexes['price']);
            $unit = $cell($raw, $indexes['unit']);
            $stockRaw = $cell($raw, $indexes['stock']);
            $reorderRaw = $cell($raw, $indexes['reorder']);
            if ($name === '' && $barcode === '' && $priceRaw === '' && $unit === '' && $stockRaw === '' && $reorderRaw === '') {
                continue;
            }
            $rowNumber = $offset + 2;
            $errors = [];
            if ($name === '' || strlen($name) > 255) {
                $errors[] = 'Product name is required (max 255 characters).';
            }
            if ($barcode !== '' && strlen($barcode) > 100) {
                $errors[] = 'Barcode is too long.';
            }
            if ($barcode !== '' && isset($seenBarcodes[$barcode])) {
                $errors[] = 'Duplicate barcode in this file (row ' . $seenBarcodes[$barcode] . ').';
            } elseif ($barcode !== '') {
                $seenBarcodes[$barcode] = $rowNumber;
            }
            $priceClean = str_replace(',', '', $priceRaw);
            if (!is_numeric($priceClean) || (float)$priceClean < 0) {
                $errors[] = 'Invalid selling price.';
            }
            if ($unit === '' || strlen($unit) > 50) {
                $errors[] = 'Unit of measurement is required.';
            }
            if (!ctype_digit($stockRaw) || (int)$stockRaw > 100000000) {
                $errors[] = 'Stock quantity must be a whole number.';
            }
            if (!ctype_digit($reorderRaw) || (int)$reorderRaw > 100000000) {
                $errors[] = 'Reorder level must be a whole number.';
            }

            $existing = null;
            if ($barcode !== '') {
                $lookup = $pdo->prepare('SELECT id, product_name FROM products WHERE barcode = ? LIMIT 1');
                $lookup->execute([$barcode]);
                $existing = $lookup->fetch();
            }
            if (!$existing && $name !== '') {
                $lookup = $pdo->prepare('SELECT id, product_name FROM products WHERE product_name = ? LIMIT 1');
                $lookup->execute([$name]);
                $existing = $lookup->fetch();
            }

            $preview[] = [
                'row' => $rowNumber,
                'product_name' => $name,
                'barcode' => $barcode,
                'selling_price' => is_numeric($priceClean) ? number_format((float)$priceClean, 2, '.', '') : $priceRaw,
                'unit' => $unit,
                'stock_quantity' => $stockRaw,
                'reorder_level' => $reorderRaw,
                'errors' => $errors,
                'valid' => !$errors,
                'existing_id' => $existing ? (int)$existing['id'] : null,
                'existing_name' => $existing ? (string)$existing['product_name'] : '',
            ];
        }
        @unlink($destination);
        if (!$preview) {
            fail('No product rows were found in the workbook.');
        }
        $token = bin2hex(random_bytes(16));
        $_SESSION['import'] = [
            'token' => $token,
            'rows' => $preview,
            'created_at' => time(),
        ];
        $hasExisting = false;
        foreach ($preview as $row) {
            if ($row['existing_id'] !== null && $row['valid']) {
                $hasExisting = true;
                break;
            }
        }
        json_ok([
            'token' => $token,
            'rows' => $preview,
            'has_existing' => $hasExisting,
            'valid_count' => count(array_filter($preview, static fn($r) => $r['valid'])),
            'error_count' => count(array_filter($preview, static fn($r) => !$r['valid'])),
        ]);
    }

    if ($action === 'import_commit') {
        $body = request_body();
        $token = sval($body, 'token');
        $overwrite = bval($body, 'overwrite');
        $import = $_SESSION['import'] ?? null;
        if (!$import || !hash_equals((string)($import['token'] ?? ''), $token)) {
            fail('The import preview has expired. Please upload the file again.');
        }
        if ((time() - (int)($import['created_at'] ?? 0)) > 1800) {
            unset($_SESSION['import']);
            fail('The import preview has expired. Please upload the file again.');
        }
        $rows = $import['rows'] ?? [];
        foreach ($rows as $row) {
            if ($row['valid'] && $row['existing_id'] !== null && !$overwrite) {
                fail('This file contains products that already exist. Confirm "Update existing products" to continue.');
            }
        }
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $pdo->beginTransaction();
        try {
            $insertStmt = $pdo->prepare(
                'INSERT INTO products (product_name, barcode, selling_price, unit, stock_quantity, reorder_level, status)
                 VALUES (?, ?, ?, ?, ?, ?, "active")'
            );
            $updateStmt = $pdo->prepare(
                'UPDATE products SET product_name = ?, barcode = ?, selling_price = ?, unit = ?,
                 stock_quantity = ?, reorder_level = ?, status = "active" WHERE id = ?'
            );
            $currentStmt = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = ? FOR UPDATE');
            foreach ($rows as $row) {
                if (!$row['valid']) {
                    $skipped++;
                    continue;
                }
                $name = (string)$row['product_name'];
                $barcode = (string)$row['barcode'] !== '' ? (string)$row['barcode'] : null;
                $price = money_val((float)$row['selling_price']);
                $unit = (string)$row['unit'];
                $stock = (int)$row['stock_quantity'];
                $reorder = (int)$row['reorder_level'];
                $existingId = (int)($row['existing_id'] ?? 0);
                if ($existingId > 0) {
                    $currentStmt->execute([$existingId]);
                    $previous = (int)$currentStmt->fetchColumn();
                    $updateStmt->execute([$name, $barcode, $price, $unit, $stock, $reorder, $existingId]);
                    if ($previous !== $stock) {
                        record_stock_movement($pdo, $existingId, 'adjustment', $stock - $previous, $previous, $stock, 'Stock updated through Excel import');
                    }
                    $updated++;
                } else {
                    $insertStmt->execute([$name, $barcode, $price, $unit, $stock, $reorder]);
                    $newId = (int)$pdo->lastInsertId();
                    if ($stock > 0) {
                        record_stock_movement($pdo, $newId, 'stock_in', $stock, 0, $stock, 'Opening stock from Excel import');
                    }
                    $inserted++;
                }
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        unset($_SESSION['import']);
        json_ok(
            ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped, 'low_stock_count' => low_stock_count($pdo)],
            "Import finished: {$inserted} added, {$updated} updated, {$skipped} skipped."
        );
    }

    fail('Unknown inventory action.', 404);
});
