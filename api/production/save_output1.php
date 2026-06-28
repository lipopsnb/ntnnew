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

$productCodeId       = (int)($_POST['product_code_id']       ?? 0);
$productionReceiptId = (int)($_POST['production_receipt_id'] ?? 0);
$outputDate          = trim($_POST['output_date']            ?? date('Y-m-d'));
$quantityCompleted   = (float)($_POST['quantity_completed']  ?? 0);
$quantityDefect      = (float)($_POST['quantity_defect']     ?? 0);
$quantityDelivered   = (float)($_POST['quantity_delivered']  ?? 0);
$note                = trim($_POST['note'] ?? '') ?: null;

if (!$productCodeId || !$productionReceiptId || ($quantityCompleted + $quantityDefect) <= 0) {
    echo json_encode(['ok'=>false,'msg'=>'Thiếu thông tin hoặc số lượng = 0']); exit;
}

// ✅ Kiểm tra FK hợp lệ
$check = $pdo->prepare("
    SELECT id FROM production_receipts WHERE id = ? AND product_code_id = ?
");
$check->execute([$productionReceiptId, $productCodeId]);
if (!$check->fetch()) {
    echo json_encode(['ok'=>false,'msg'=>'Phiếu nhận không hợp lệ hoặc không khớp mã SP']); exit;
}

try {
    // Sinh số output OUT-YYYYMMDD-XXX
    $pdo->prepare("
        INSERT INTO document_sequences (doc_type, doc_date, last_seq) VALUES ('OUT',?,1)
        ON DUPLICATE KEY UPDATE last_seq = last_seq + 1
    ")->execute([$outputDate]);
    $seq = $pdo->query("
        SELECT last_seq FROM document_sequences
        WHERE doc_type='OUT' AND doc_date='$outputDate'
    ")->fetchColumn();
    $outputNo = 'OUT-' . date('Ymd', strtotime($outputDate)) . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);

    // ✅ Truyền đầy đủ production_receipt_id
    $pdo->prepare("
        INSERT INTO production_outputs
            (output_no, output_date, production_receipt_id, product_code_id,
             quantity_completed, quantity_defect, quantity_delivered,
             note, created_by)
        VALUES (?,?,?,?,?,?,?,?,?)
    ")->execute([
        $outputNo, $outputDate, $productionReceiptId, $productCodeId,
        $quantityCompleted, $quantityDefect, $quantityDelivered,
        $note, $user['id']
    ]);

    echo json_encode(['ok'=>true,'msg'=>'Đã lưu output','output_no'=>$outputNo]);
} catch (Throwable $e) {
    error_log($e->getMessage());
    echo json_encode(['ok'=>false,'msg'=>'Lỗi hệ thống: '.$e->getMessage()]);
}