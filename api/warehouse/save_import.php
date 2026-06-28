<?php
/**
 * API: Tạo phiếu nhập kho SP gia công
 * POST: import_date, product_code_id, quantity, note, csrf_token
 * → Tạo warehouse_imports
 * → Cộng warehouse_stock.qty_pending
 * → Ghi warehouse_stock_log
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';

header('Content-Type: application/json');
requireLogin();
requireRole('director','accountant','warehouse','manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']); exit;
}

if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid CSRF token']); exit;
}

$pdo  = getDBConnection();
$user = currentUser();

$importDate    = trim($_POST['import_date']    ?? '');
$productCodeId = (int)($_POST['product_code_id'] ?? 0);
$quantity      = (float)($_POST['quantity']    ?? 0);
$note          = trim($_POST['note']           ?? '');

// Validate
if (!$importDate || !$productCodeId || $quantity <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Vui lòng nhập đầy đủ thông tin']); exit;
}

// Kiểm tra mã SP tồn tại
$pc = $pdo->prepare("SELECT id FROM product_codes WHERE id = ? AND is_active = 1");
$pc->execute([$productCodeId]);
if (!$pc->fetch()) {
    echo json_encode(['ok' => false, 'msg' => 'Mã sản phẩm không hợp lệ']); exit;
}

// Sinh số phiếu: WI-YYYYMMDD-XXXX
$prefix  = 'WI-' . date('Ymd', strtotime($importDate)) . '-';
$lastNo  = $pdo->prepare("
    SELECT import_no FROM warehouse_imports
    WHERE import_no LIKE ? ORDER BY id DESC LIMIT 1
");
$lastNo->execute([$prefix . '%']);
$lastRow = $lastNo->fetchColumn();
$seq     = $lastRow ? ((int)substr($lastRow, -4) + 1) : 1;
$importNo = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);

try {
    $pdo->beginTransaction();

    // 1. Tạo warehouse_imports
    $stmt = $pdo->prepare("
        INSERT INTO warehouse_imports
            (import_no, import_date, product_code_id, quantity, quantity_sent, note,
             status, created_by)
        VALUES (?, ?, ?, ?, 0, ?, 'pending', ?)
    ");
    $stmt->execute([$importNo, $importDate, $productCodeId, $quantity, $note, $user['id']]);
    $importId = $pdo->lastInsertId();

    // 2. Cộng warehouse_stock.qty_pending
    $pdo->prepare("
        INSERT INTO warehouse_stock (product_code_id, qty_pending)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE qty_pending = qty_pending + VALUES(qty_pending)
    ")->execute([$productCodeId, $quantity]);

    // 3. Ghi log
    $pdo->prepare("
        INSERT INTO warehouse_stock_log
            (product_code_id, log_date, txn_type, stock_type,
             qty_change, ref_table, ref_id, note, created_by)
        VALUES (?, ?, 'import', 'pending', ?, 'warehouse_imports', ?, ?, ?)
    ")->execute([
        $productCodeId, $importDate, $quantity,
        $importId, "Nhập kho: $importNo", $user['id']
    ]);

    $pdo->commit();
    echo json_encode(['ok' => true, 'import_no' => $importNo]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}