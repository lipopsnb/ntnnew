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

if (!$assignId) {
    echo json_encode(['ok' => false, 'msg' => 'Dữ liệu không hợp lệ']); exit;
}

try {
    $pdo->beginTransaction();

    // Xoá kết quả trước (nếu có)
    $pdo->prepare("DELETE FROM kpi_results WHERE kpi_assignment_id = ?")
        ->execute([$assignId]);

    // Xoá phân bổ
    $stmt = $pdo->prepare("DELETE FROM kpi_assignments WHERE id = ?");
    $stmt->execute([$assignId]);

    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy KPI']); exit;
    }

    $pdo->commit();
    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}