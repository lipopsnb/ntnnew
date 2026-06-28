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

$entryDate   = trim($_POST['entry_date']    ?? date('Y-m-d'));
$costType    = trim($_POST['cost_type']     ?? 'other');
$supplier    = trim($_POST['supplier_name'] ?? '') ?: null;
$description = trim($_POST['description']  ?? '');
$quantity    = (float)($_POST['quantity']   ?? 0);
$unit        = trim($_POST['unit']          ?? '') ?: null;
$unitPrice   = (int)($_POST['unit_price']   ?? 0);
$totalAmount = (int)($_POST['total_amount'] ?? 0);
$invoiceNo   = trim($_POST['invoice_no']   ?? '') ?: null;
$note        = trim($_POST['note']          ?? '') ?: null;

if (!$description || $totalAmount <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu mô tả hoặc thành tiền']); exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO cost_entries
            (entry_date, cost_type, supplier_name, description, quantity, unit,
             unit_price, total_amount, invoice_no, note, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $entryDate, $costType, $supplier, $description,
        $quantity, $unit, $unitPrice, $totalAmount,
        $invoiceNo, $note, $user['id']
    ]);
    echo json_encode(['ok' => true, 'msg' => 'Đã lưu chi phí']);
} catch (Throwable $e) {
    error_log($e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống']);
}