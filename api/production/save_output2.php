<?php
/**
 * API: Nhập output cuối ngày SX
 * POST: output_date, production_receipt_id, product_code_id,
 *       quantity_completed, quantity_defect, note, csrf_token
 * → Tạo production_outputs
 * → Cập nhật production_stock (trừ pending, cộng completed + defect)
 * → KHÔNG nhập quantity_delivered ở đây (tính từ delivery_note_items)
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

$outputDate    = trim($_POST['output_date']           ?? '');
$receiptId     = (int)($_POST['production_receipt_id'] ?? 0);
$productCodeId = (int)($_POST['product_code_id']      ?? 0);
$qtyCompleted  = (float)($_POST['quantity_completed'] ?? 0);
$qtyDefect     = (float)($_POST['quantity_defect']    ?? 0);
$note          = trim($_POST['note']                  ?? '');

if (!$outputDate || !$receiptId || !$productCodeId) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu thông tin bắt buộc']); exit;
}
if ($qtyCompleted < 0 || $qtyDefect < 0) {
    echo json_encode(['ok' => false, 'msg' => 'Số lượng không được âm']); exit;
}
if ($qtyCompleted + $qtyDefect <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Vui lòng nhập ít nhất 1 loại số lượng']); exit;
}

try {
    $pdo->beginTransaction();

    // Kiểm tra phiếu nhận SX
    $pr = $pdo->prepare("
        SELECT pr.id, pr.quantity_received, pr.product_code_id,
               COALESCE(SUM(po.quantity_completed + po.quantity_defect), 0) AS used
        FROM production_receipts pr
        LEFT JOIN production_outputs po ON po.production_receipt_id = pr.id
        WHERE pr.id = ?
        GROUP BY pr.id
        FOR UPDATE
    ");
    $pr->execute([$receiptId]);
    $prRow = $pr->fetch(PDO::FETCH_ASSOC);

    if (!$prRow) {
        throw new Exception('Phiếu nhận SX không tồn tại');
    }
    $remaining = $prRow['quantity_received'] - $prRow['used'];
    $total     = $qtyCompleted + $qtyDefect;
    if ($total > $remaining) {
        throw new Exception("Tổng SL báo cáo ($total) vượt quá còn lại trong SX ($remaining)");
    }

    // Sinh số output: PO-YYYYMMDD-XXXX
    $prefix  = 'PO-' . date('Ymd', strtotime($outputDate)) . '-';
    $lastNo  = $pdo->prepare("
        SELECT output_no FROM production_outputs
        WHERE output_no LIKE ? ORDER BY id DESC LIMIT 1
    ");
    $lastNo->execute([$prefix . '%']);
    $lastRow  = $lastNo->fetchColumn();
    $seq      = $lastRow ? ((int)substr($lastRow, -4) + 1) : 1;
    $outputNo = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);

    // 1. Tạo production_outputs (quantity_delivered = 0, sẽ update từ delivery)
    $pdo->prepare("
        INSERT INTO production_outputs
            (output_no, output_date, production_receipt_id, product_code_id,
             quantity_completed, quantity_defect, quantity_delivered, note, created_by)
        VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)
    ")->execute([
        $outputNo, $outputDate, $receiptId, $productCodeId,
        $qtyCompleted, $qtyDefect, $note, $user['id']
    ]);
    $outputId = $pdo->lastInsertId();

    // 2. Cập nhật production_stock
    // qty_pending -= (completed + defect)
    // qty_completed += completed
    // qty_defect    += defect
    $pdo->prepare("
        INSERT INTO production_stock
            (product_code_id, stock_date, qty_pending, qty_completed, qty_defect)
        VALUES (?, ?, 0, ?, ?)
        ON DUPLICATE KEY UPDATE
            qty_pending   = GREATEST(0, qty_pending - ?),
            qty_completed = qty_completed + ?,
            qty_defect    = qty_defect + ?
    ")->execute([
        $productCodeId,
        $outputDate,
        $qtyCompleted,
        $qtyDefect,
        $total,
        $qtyCompleted,
        $qtyDefect
    ]);

    $pdo->commit();
    echo json_encode(['ok' => true, 'output_no' => $outputNo]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}