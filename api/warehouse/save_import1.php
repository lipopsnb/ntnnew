<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
header('Content-Type: application/json');
requireLogin();
requireRole('director','accountant','warehouse','manager');

$pdo  = getDBConnection();
$user = currentUser();

if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'CSRF invalid']); exit;
}

$productCodeId = (int)($_POST['product_code_id'] ?? 0);
$importDate    = trim($_POST['import_date'] ?? date('Y-m-d'));
$quantity      = (float)($_POST['quantity'] ?? 0);
$note          = trim($_POST['note'] ?? '') ?: null;

if (!$productCodeId || $quantity <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu thông tin bắt buộc']); exit;
}

try {
    // ── Sinh số phiếu WH-YYYYMMDD-XXX ────────────────────────────────
    $dateKey = date('Ymd', strtotime($importDate));
    $pdo->beginTransaction();

    // Upsert document_sequences
    $pdo->prepare("
        INSERT INTO document_sequences (doc_type, doc_date, last_seq)
        VALUES ('WH', ?, 1)
        ON DUPLICATE KEY UPDATE last_seq = last_seq + 1
    ")->execute([$importDate]);

    $seq = $pdo->query("
        SELECT last_seq FROM document_sequences
        WHERE doc_type = 'WH' AND doc_date = '$importDate'
    ")->fetchColumn();

    $importNo = 'WH-' . $dateKey . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);

    // Lấy description từ product_codes
    $desc = $pdo->prepare("SELECT description FROM product_codes WHERE id = ?");
    $desc->execute([$productCodeId]);
    $description = $desc->fetchColumn();

    // Insert phiếu nhập
    $stmt = $pdo->prepare("
        INSERT INTO warehouse_imports
            (import_no, import_date, product_code_id, description, quantity, note, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$importNo, $importDate, $productCodeId, $description, $quantity, $note, $user['id']]);

    $pdo->commit();
    echo json_encode(['ok' => true, 'msg' => 'Đã tạo phiếu nhập', 'import_no' => $importNo]);

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log($e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}