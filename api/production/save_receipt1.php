<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
header('Content-Type: application/json');
requireLogin();
requireRole('director','accountant','warehouse','production','manager');

$pdo  = getDBConnection();
$user = currentUser();

if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok'=>false,'msg'=>'CSRF invalid']); exit;
}

$importId      = (int)($_POST['warehouse_import_id'] ?? 0);
$productCodeId = (int)($_POST['product_code_id']     ?? 0);
$receiptDate   = trim($_POST['receipt_date']         ?? date('Y-m-d'));
$quantity      = (float)($_POST['quantity']          ?? 0);
$note          = trim($_POST['note'] ?? '') ?: null;

if (!$importId || !$productCodeId || $quantity <= 0) {
    echo json_encode(['ok'=>false,'msg'=>'Thiếu thông tin']); exit;
}

try {
    $pdo->beginTransaction();

    // ✅ Kiểm tra available dùng đúng tên cột
    $avail = $pdo->prepare("
        SELECT (quantity - quantity_sent) FROM warehouse_imports WHERE id = ? FOR UPDATE
    ");
    $avail->execute([$importId]);
    $available = (float)$avail->fetchColumn();

    if ($quantity > $available) {
        $pdo->rollBack();
        echo json_encode(['ok'=>false,'msg'=>"Chỉ còn $available, không đủ $quantity"]); exit;
    }

    // Sinh số phiếu SX-YYYYMMDD-XXX
    $pdo->prepare("
        INSERT INTO document_sequences (doc_type, doc_date, last_seq) VALUES ('SX',?,1)
        ON DUPLICATE KEY UPDATE last_seq = last_seq + 1
    ")->execute([$receiptDate]);
    $seq = $pdo->query("
        SELECT last_seq FROM document_sequences
        WHERE doc_type='SX' AND doc_date='$receiptDate'
    ")->fetchColumn();
    $receiptNo = 'SX-' . date('Ymd', strtotime($receiptDate)) . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);

    // ✅ Dùng đúng tên cột quantity_received
    $pdo->prepare("
        INSERT INTO production_receipts
            (receipt_no, receipt_date, warehouse_import_id, product_code_id,
             quantity_received, note, created_by)
        VALUES (?,?,?,?,?,?,?)
    ")->execute([$receiptNo, $receiptDate, $importId, $productCodeId, $quantity, $note, $user['id']]);

    // Cập nhật warehouse_imports
    $pdo->prepare("
        UPDATE warehouse_imports
        SET quantity_sent = quantity_sent + ?,
            status = CASE
                WHEN quantity_sent + ? >= quantity THEN 'completed'
                WHEN quantity_sent + ? > 0         THEN 'partial'
                ELSE status
            END
        WHERE id = ?
    ")->execute([$quantity, $quantity, $quantity, $importId]);

    $pdo->commit();
    echo json_encode(['ok'=>true,'msg'=>'Đã tạo phiếu nhận','receipt_no'=>$receiptNo]);

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log($e->getMessage());
    echo json_encode(['ok'=>false,'msg'=>'Lỗi hệ thống: '.$e->getMessage()]);
}