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

$pdo          = getDBConnection();
$user         = currentUser();
$resultId     = (int)($_POST['result_id']    ?? 0);
$actualQty    = (int)($_POST['actual_qty']   ?? 0);
$isDeducted   = (int)($_POST['is_deducted']  ?? 0);
$reason       = trim($_POST['reason']        ?? '');
$salaryActual = (float)($_POST['salary_actual']  ?? 0);
$salaryPerDay = (float)($_POST['salary_per_day'] ?? 0);

if (!$resultId || $actualQty < 0) {
    echo json_encode(['ok' => false, 'msg' => 'Dữ liệu không hợp lệ']); exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE kpi_results SET
            actual_qty     = ?,
            is_deducted    = ?,
            reason         = ?,
            salary_actual  = ?,
            salary_per_day = ?,
            confirmed_by   = ?,
            confirmed_at   = NOW(),
            updated_at     = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $actualQty, $isDeducted, $reason,
        $salaryActual, $salaryPerDay,
        $user['id'], $resultId
    ]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy kết quả KPI']); exit;
    }

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}