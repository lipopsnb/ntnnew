<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireLogin();
requireRole('director', 'accountant');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']); exit;
}

if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'CSRF không hợp lệ']); exit;
}

$pdo      = getDBConnection();
$assignId = (int)($_POST['assign_id'] ?? 0);
$target   = (int)($_POST['kpi_target'] ?? 0);

if (!$assignId || $target < 1) {
    echo json_encode(['ok' => false, 'msg' => 'Dữ liệu không hợp lệ']); exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE kpi_assignments
        SET kpi_target = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$target, $assignId]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy KPI']); exit;
    }

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}