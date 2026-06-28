<?php
/**
 * API: SX nhận hàng từ kho
 * POST: receipt_date, warehouse_import_id, product_code_id, quantity, note, csrf_token
 * → Tạo production_receipts
 * → Cập nhật warehouse_imports.quantity_sent + status
 * → Trừ warehouse_stock.qty_pending
 * → Cộng production_stock.qty_pending
 * → Ghi warehouse_stock_log
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';

header('Content-Type: application/json');
requireLogin();
requireRole('director','accountant','warehouse','production','manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']); exit;
}
if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid CSRF token']); exit;
}

$pdo  = getDBConnection();
$user = currentUser();

$receiptDate     = trim($_POST['receipt_date']       ?? '');
$warehouseImport = (int)($_POST['warehouse_import_id'] ?? 0);
$productCodeId   = (int)($_POST['product_code_id']   ?? 0);
$quantity        = (float)($_POST['quantity']         ?? 0);
$note            = trim($_POST['note']                ?? '');

if (!$receiptDate || !$warehouseImport || !$productCodeId || $quantity <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Vui lòng nhập đầy đủ thông tin']); exit;
}

// Kiểm tra phiếu nhập còn đủ hàng không
$wi = $pdo->prepare("
    SELECT id, quantity, quantity_sent, status
    FROM warehouse_imports WHERE id = ? FOR UPDATE
");
// FOR UPDATE sẽ dùng trong transaction

try {
    $pdo->beginTransaction();

    $wi = $pdo->prepare("
        SELECT id, quantity, quantity_sent, status
        FROM warehouse_imports WHERE id = ? FOR UPDATE
    ");
    $wi->execute([$warehouseImport]);
    $wiRow = $wi->fetch(PDO::FETCH_ASSOC);

    if (!$wiRow) {
        throw new Exception('Phiếu nhập không tồn tại');
    }
    $available = $wiRow['quantity'] - $wiRow['quantity_sent'];
    if ($quantity > $available) {
        throw new Exception("Số lượng vượt quá tồn phiếu. Còn: " . number_format($available));
    }

    // Sinh số phiếu nhận: PR-YYYYMMDD-XXXX
    $prefix  = 'PR-' . date('Ymd', strtotime($receiptDate)) . '-';
    $lastNo  = $pdo->prepare("
        SELECT receipt_no FROM production_receipts
        WHERE receipt_no LIKE ? ORDER BY id DESC LIMIT 1
    ");
    $lastNo->execute([$prefix . '%']);
    $lastRow  = $lastNo->fetchColumn();
    $seq      = $lastRow ? ((int)substr($lastRow, -4) + 1) : 1;
    $receiptNo = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);

    // 1. Tạo production_receipts
    $pdo->prepare("
        INSERT INTO production_receipts
            (receipt_no, receipt_date, warehouse_import_id, product_code_id,
             quantity_received, note, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $receiptNo, $receiptDate, $warehouseImport,
        $productCodeId, $quantity, $note, $user['id']
    ]);
    $receiptId = $pdo->lastInsertId();

    // 2. Cập nhật warehouse_imports
    $newSent   = $wiRow['quantity_sent'] + $quantity;
    $newStatus = ($newSent >= $wiRow['quantity']) ? 'completed' : 'partial';
    $pdo->prepare("
        UPDATE warehouse_imports
        SET quantity_sent = ?, status = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([$newSent, $newStatus, $warehouseImport]);

    // 3. Trừ warehouse_stock.qty_pending
    $pdo->prepare("
        INSERT INTO warehouse_stock (product_code_id, qty_pending)
        VALUES (?, 0)
        ON DUPLICATE KEY UPDATE qty_pending = GREATEST(0, qty_pending - ?)
    ")->execute([$productCodeId, $quantity]);
    // Fix: thực hiện update riêng để tránh lỗi ON DUPLICATE với 2 giá trị khác nhau
    $pdo->prepare("
        UPDATE warehouse_stock
        SET qty_pending = GREATEST(0, qty_pending - ?)
        WHERE product_code_id = ?
    ")->execute([$quantity, $productCodeId]);

    // 4. Cộng production_stock.qty_pending
    $pdo->prepare("
        INSERT INTO production_stock (product_code_id, stock_date, qty_pending)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE qty_pending = qty_pending + VALUES(qty_pending)
    ")->execute([$productCodeId, $receiptDate, $quantity]);

    // 5. Ghi log: xuất kho (âm)
    $pdo->prepare("
        INSERT INTO warehouse_stock_log
            (product_code_id, log_date, txn_type, stock_type,
             qty_change, ref_table, ref_id, note, created_by)
        VALUES (?, ?, 'send_to_prod', 'pending', ?, 'production_receipts', ?, ?, ?)
    ")->execute([
        $productCodeId, $receiptDate, -$quantity,
        $receiptId, "Chuyển SX: $receiptNo", $user['id']
    ]);

    $pdo->commit();
    echo json_encode(['ok' => true, 'receipt_no' => $receiptNo]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}