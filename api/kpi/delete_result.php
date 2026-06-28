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
$resultId = (int)($_POST['result_id'] ?? 0);

if (!$resultId) {
    echo json_encode(['ok' => false, 'msg' => 'Dữ liệu không hợp lệ']); exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM kpi_results WHERE id = ?");
    $stmt->execute([$resultId]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy kết quả KPI']); exit;
    }

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}