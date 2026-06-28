<?php
/**
 * API: Tạo hóa đơn (hỗ trợ nhiều phiếu giao hàng qua invoice_deliveries).
 * POST: csrf_token, customer_id, invoice_date, due_date, tax_rate (0 hoặc 8),
 *       delivery_ids[] (mảng ID phiếu giao), note
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

header('Content-Type: application/json; charset=utf-8');
requireRole('director', 'accountant', 'manager');

$pdo = erp_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']);
    exit;
}

if (!erp_validate_csrf($_POST['csrf_token'] ?? null)) {
    echo json_encode(['ok' => false, 'msg' => 'CSRF token không hợp lệ']);
    exit;
}

$customerId  = (int) ($_POST['customer_id']  ?? 0);
$invoiceDate = trim((string) ($_POST['invoice_date'] ?? date('Y-m-d')));
$dueDate     = trim((string) ($_POST['due_date'] ?? '')) ?: null;
$taxRate     = (float) ($_POST['tax_rate'] ?? 0);
$note        = trim((string) ($_POST['note'] ?? '')) ?: null;
$deliveryIds = array_map('intval', (array) ($_POST['delivery_ids'] ?? []));
$deliveryIds = array_filter($deliveryIds, static fn(int $v): bool => $v > 0);
$deliveryIds = array_values(array_unique($deliveryIds));

if ($customerId <= 0 || $invoiceDate === '' || empty($deliveryIds)) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu khách hàng, ngày hóa đơn hoặc phiếu giao hàng']);
    exit;
}

// Chỉ cho phép VAT 0% hoặc 8%
if (!in_array((int) $taxRate, [0, 8], true)) {
    echo json_encode(['ok' => false, 'msg' => 'Thuế suất chỉ được phép là 0% hoặc 8%']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Xác nhận tất cả delivery_ids thuộc về customer và chưa có trong hóa đơn nào
    $placeholders = implode(',', array_fill(0, count($deliveryIds), '?'));
    $checkStmt = $pdo->prepare("
        SELECT d.id
        FROM deliveries d
        LEFT JOIN invoice_deliveries idl ON idl.delivery_id = d.id
        WHERE d.id IN ($placeholders)
          AND d.customer_id = ?
          AND idl.id IS NULL
    ");
    $checkStmt->execute([...$deliveryIds, $customerId]);
    $validIds = array_column($checkStmt->fetchAll(PDO::FETCH_ASSOC), 'id');

    if (count($validIds) !== count($deliveryIds)) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Một hoặc nhiều phiếu giao hàng không hợp lệ hoặc đã được lập hóa đơn']);
        exit;
    }

    // Lấy tất cả delivery_items của các phiếu đã chọn
    $itemStmt = $pdo->prepare("
        SELECT di.qty_delivered, di.unit_price, di.amount
        FROM delivery_items di
        WHERE di.delivery_id IN ($placeholders)
    ");
    $itemStmt->execute($deliveryIds);
    $allItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($allItems)) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Các phiếu giao hàng chưa có dòng sản phẩm']);
        exit;
    }

    $subtotal  = array_sum(array_column($allItems, 'amount'));
    $taxAmount = round($subtotal * $taxRate / 100, 2);
    $total     = $subtotal + $taxAmount;

    $invoiceCode = erp_generate_daily_code($pdo, 'invoices', 'invoice_code', 'INV', $invoiceDate);

    // delivery_id backward compat: dùng delivery đầu tiên nếu chỉ có 1
    $backCompatDeliveryId = count($deliveryIds) === 1 ? $deliveryIds[0] : null;

    $status = ($dueDate && strtotime($dueDate) < strtotime(date('Y-m-d'))) ? 'unpaid' : 'unpaid';

    $insInvoice = $pdo->prepare("
        INSERT INTO invoices
            (invoice_code, delivery_id, customer_id, invoice_date, due_date,
             subtotal, tax_rate, tax_amount, total_amount, paid_amount, status, note, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)
    ");
    $insInvoice->execute([
        $invoiceCode, $backCompatDeliveryId, $customerId, $invoiceDate, $dueDate,
        $subtotal, $taxRate, $taxAmount, $total, $status, $note,
        erp_current_user_id() ?: null,
    ]);
    $invoiceId = (int) $pdo->lastInsertId();

    // Ghi invoice_deliveries
    $insId = $pdo->prepare('INSERT INTO invoice_deliveries (invoice_id, delivery_id) VALUES (?, ?)');
    foreach ($deliveryIds as $dlId) {
        $insId->execute([$invoiceId, $dlId]);
    }

    $pdo->commit();

    echo json_encode([
        'ok'           => true,
        'msg'          => 'Đã tạo hóa đơn ' . $invoiceCode,
        'invoice_code' => $invoiceCode,
        'id'           => $invoiceId,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('save_invoice error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}
