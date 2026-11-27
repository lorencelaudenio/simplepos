<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../includes/xlsx.php';

$action = $_GET['action'] ?? '';

api_route(function () use ($action) {
    $pdo = db();
    $filter = (string)($_GET['filter'] ?? 'today');
    $from = (string)($_GET['from'] ?? '');
    $to = (string)($_GET['to'] ?? '');
    $range = resolve_date_range($filter, $from, $to);
    $where = sales_where($range);

    if ($action === 'dashboard') {
        $summaryStmt = $pdo->prepare(
            'SELECT COUNT(*) AS receipts, COALESCE(SUM(total_amount), 0) AS total,
                    COALESCE(AVG(total_amount), 0) AS average
             FROM sales WHERE ' . $where['sql']
        );
        $summaryStmt->execute($where['params']);
        $summaryRow = $summaryStmt->fetch();

        $chartStmt = $pdo->prepare(
            'SELECT DATE(created_at) AS sale_date, SUM(total_amount) AS total, COUNT(*) AS receipts
             FROM sales WHERE ' . $where['sql'] . ' GROUP BY DATE(created_at) ORDER BY sale_date ASC'
        );
        $chartStmt->execute($where['params']);
        $chartRows = $chartStmt->fetchAll();
        $labels = [];
        $values = [];
        foreach ($chartRows as $row) {
            $labels[] = date('M d', strtotime((string)$row['sale_date']));
            $values[] = round((float)$row['total'], 2);
        }

        $salesStmt = $pdo->prepare(
            'SELECT s.id, s.receipt_number, s.created_at, s.total_amount, s.cash_received, s.change_amount,
                    COALESCE(SUM(si.quantity), 0) AS item_count
             FROM sales s
             LEFT JOIN sale_items si ON si.sale_id = s.id
             WHERE ' . str_replace('created_at', 's.created_at', $where['sql']) . '
             GROUP BY s.id, s.receipt_number, s.created_at, s.total_amount, s.cash_received, s.change_amount
             ORDER BY s.created_at DESC, s.id DESC
             LIMIT 1000'
        );
        $salesStmt->execute($where['params']);
        $sales = [];
        foreach ($salesStmt->fetchAll() as $sale) {
            $sales[] = [
                'id' => (int)$sale['id'],
                'receipt_number' => (string)$sale['receipt_number'],
                'created_at' => (string)$sale['created_at'],
                'total_amount' => (float)$sale['total_amount'],
                'cash_received' => (float)$sale['cash_received'],
                'change_amount' => (float)$sale['change_amount'],
                'item_count' => (int)$sale['item_count'],
            ];
        }

        json_ok([
            'summary' => [
                'total' => round((float)$summaryRow['total'], 2),
                'receipts' => (int)$summaryRow['receipts'],
                'average' => round((float)$summaryRow['average'], 2),
            ],
            'chart' => ['labels' => $labels, 'values' => $values],
            'sales' => $sales,
            'filter' => $filter,
            'from' => $from,
            'to' => $to,
        ]);
    }

    if ($action === 'export') {
        $stmt = $pdo->prepare(
            'SELECT s.receipt_number, s.created_at, si.product_name, si.quantity, si.unit,
                    si.unit_price, si.subtotal, s.total_amount, s.cash_received, s.change_amount
             FROM sales s
             INNER JOIN sale_items si ON si.sale_id = s.id
             WHERE ' . str_replace('created_at', 's.created_at', $where['sql']) . '
             ORDER BY s.created_at DESC, s.id ASC, si.id ASC'
        );
        $stmt->execute($where['params']);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                $row['receipt_number'],
                $row['created_at'],
                $row['product_name'],
                (int)$row['quantity'],
                $row['unit'],
                money_val($row['unit_price']),
                money_val($row['subtotal']),
                money_val($row['total_amount']),
                money_val($row['cash_received']),
                money_val($row['change_amount']),
            ];
        }
        xlsx_send(
            'sales_' . date('Ymd_His') . '.xlsx',
            [
                'Receipt Number', 'Transaction Date', 'Product Name', 'Quantity', 'Unit',
                'Selling Price', 'Subtotal', 'Total Transaction Amount', 'Cash Received', 'Change',
            ],
            $rows
        );
    }

    fail('Unknown report action.', 404);
});
