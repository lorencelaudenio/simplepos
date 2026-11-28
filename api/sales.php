<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_bootstrap.php';

function sale_details(PDO $pdo, int $saleId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM sales WHERE id = ? LIMIT 1');
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch();
    if (!$sale) {
        return null;
    }
    $itemStmt = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id ASC');
    $itemStmt->execute([$saleId]);
    $items = [];
    foreach ($itemStmt->fetchAll() as $item) {
        $items[] = [
            'id' => (int)$item['id'],
            'product_id' => (int)$item['product_id'],
            'product_name' => (string)$item['product_name'],
            'quantity' => (int)$item['quantity'],
            'unit' => (string)$item['unit'],
            'unit_price' => (float)$item['unit_price'],
            'subtotal' => (float)$item['subtotal'],
        ];
    }
    return [
        'id' => (int)$sale['id'],
        'receipt_number' => (string)$sale['receipt_number'],
        'created_at' => (string)$sale['created_at'],
        'total_amount' => (float)$sale['total_amount'],
        'cash_received' => (float)$sale['cash_received'],
        'change_amount' => (float)$sale['change_amount'],
        'item_count' => array_sum(array_column($items, 'quantity')),
        'store_name' => get_setting('store_name', 'My Store'),
        'store_address' => get_setting('store_address', ''),
        'tin_id' => get_setting('tin_id', ''),
        'contact_number' => get_setting('contact_number', ''),
        'receipt_footer' => get_setting('receipt_footer', 'Thank you for shopping with us!'),
        'items' => $items,
    ];
}

$action = $_GET['action'] ?? '';

api_route(function () use ($action) {
    require_pos_user();
    $pdo = db();

    if ($action === 'token') {
        if (!isset($_SESSION['checkout_tokens']) || !is_array($_SESSION['checkout_tokens'])) {
            $_SESSION['checkout_tokens'] = [];
        }
        foreach ($_SESSION['checkout_tokens'] as $key => $time) {
            if ((time() - (int)$time) > 600) {
                unset($_SESSION['checkout_tokens'][$key]);
            }
        }
        $token = bin2hex(random_bytes(20));
        $_SESSION['checkout_tokens'][$token] = time();
        json_ok(['checkout_token' => $token]);
    }

    if ($action === 'checkout') {
        $body = request_body();
        $token = sval($body, 'checkout_token');
        if ($token === '' || empty($_SESSION['checkout_tokens'][$token])
            || (time() - (int)$_SESSION['checkout_tokens'][$token]) > 600) {
            fail('This checkout has expired. Please refresh and try again.', 409);
        }
        unset($_SESSION['checkout_tokens'][$token]);

        $rawItems = $body['items'] ?? null;
        if (!is_array($rawItems) || !$rawItems) {
            fail('Your cart is empty.');
        }
        if (count($rawItems) > 100) {
            fail('A transaction may contain at most 100 different products.');
        }
        $quantities = [];
        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                fail('Invalid cart item.');
            }
            $id = ival($item, 'id');
            $qty = ival($item, 'quantity');
            if ($id <= 0 || $qty <= 0 || $qty > 100000) {
                fail('Invalid quantity in your cart.');
            }
            $quantities[$id] = ($quantities[$id] ?? 0) + $qty;
        }
        if (!is_numeric($body['cash_received'] ?? null)) {
            fail('Enter a valid cash amount.');
        }
        $cash = round(fval($body, 'cash_received'), 2);
        if ($cash < 0 || $cash > 100000000) {
            fail('Enter a valid cash amount.');
        }

        $pdo->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($quantities), '?'));
            $stmt = $pdo->prepare(
                'SELECT id, product_name, selling_price, unit, stock_quantity, status
                 FROM products WHERE id IN (' . $placeholders . ') FOR UPDATE'
            );
            $stmt->execute(array_keys($quantities));
            $products = [];
            foreach ($stmt->fetchAll() as $product) {
                $products[(int)$product['id']] = $product;
            }

            $lines = [];
            $total = 0.0;
            foreach ($quantities as $productId => $qty) {
                $product = $products[$productId] ?? null;
                if (!$product || $product['status'] !== 'active') {
                    fail('One or more products in your cart are no longer available.');
                }
                if ((int)$product['stock_quantity'] < $qty) {
                    fail('Insufficient stock for ' . $product['product_name'] . '. Available: ' . (int)$product['stock_quantity'] . '.');
                }
                $unitPrice = round((float)$product['selling_price'], 2);
                $subtotal = round($unitPrice * $qty, 2);
                $lines[] = [
                    'id' => $productId,
                    'name' => (string)$product['product_name'],
                    'unit' => (string)$product['unit'],
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                    'previous_stock' => (int)$product['stock_quantity'],
                ];
                $total = round($total + $subtotal, 2);
            }

            if ((int)round($cash * 100) < (int)round($total * 100)) {
                fail('Cash received is less than the total amount.');
            }
            $change = round($cash - $total, 2);
            $user = current_user();

            $receiptNumber = '';
            $saleId = 0;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $receiptNumber = generate_receipt_number($pdo);
                try {
                    $insertSale = $pdo->prepare(
                        'INSERT INTO sales (receipt_number, total_amount, cash_received, change_amount, user_id)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $insertSale->execute([
                        $receiptNumber,
                        money_val($total),
                        money_val($cash),
                        money_val($change),
                        $user['id'] ?? null,
                    ]);
                    $saleId = (int)$pdo->lastInsertId();
                    break;
                } catch (PDOException $ex) {
                    if ($attempt === 4 || ($ex->errorInfo[1] ?? null) != 1062) {
                        throw $ex;
                    }
                }
            }
            if ($saleId <= 0) {
                fail('Unable to generate a unique receipt number. Please try again.', 500);
            }

            $insertItem = $pdo->prepare(
                'INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit, unit_price, subtotal)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $deduct = $pdo->prepare(
                'UPDATE products SET stock_quantity = stock_quantity - ?
                 WHERE id = ? AND status = "active" AND stock_quantity >= ?'
            );
            foreach ($lines as $line) {
                $insertItem->execute([
                    $saleId,
                    $line['id'],
                    $line['name'],
                    $line['qty'],
                    $line['unit'],
                    money_val($line['unit_price']),
                    money_val($line['subtotal']),
                ]);
                $deduct->execute([$line['qty'], $line['id'], $line['qty']]);
                if ($deduct->rowCount() !== 1) {
                    fail('Stock changed during checkout. No items were deducted; please try again.', 409);
                }
                record_stock_movement(
                    $pdo,
                    $line['id'],
                    'sale',
                    -$line['qty'],
                    $line['previous_stock'],
                    $line['previous_stock'] - $line['qty'],
                    'Sale ' . $receiptNumber
                );
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }

        json_ok([
            'sale' => sale_details($pdo, $saleId),
            'low_stock_count' => low_stock_count($pdo),
        ], 'Payment successful. Receipt ' . $receiptNumber);
    }

    if ($action === 'get' || $action === 'receipt') {
        $sale = sale_details($pdo, ival($_GET, 'id'));
        if (!$sale) {
            fail('Receipt not found.', 404);
        }
        json_ok(['sale' => $sale]);
    }

    fail('Unknown sales action.', 404);
});
