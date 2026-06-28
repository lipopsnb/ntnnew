<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireLogin();
requireRole('director', 'accountant', 'manager', 'warehouse', 'production');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']); exit;
}

if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'CSRF không hợp lệ']); exit;
}

$pdo        = getDBConnection();
$user       = currentUser();
$assignDate = $_POST['assign_date'] ?? date('Y-m-d');
$users      = $_POST['users'] ?? [];
$kpis       = $_POST['kpi'] ?? [];

if (empty($users)) {
    echo json_encode(['ok' => false, 'msg' => 'Chưa chọn nhân viên']); exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO kpi_assignments (assign_date, user_id, manager_id, kpi_target)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            kpi_target = VALUES(kpi_target),
            manager_id = VALUES(manager_id)
    ");

    foreach ($users as $uid) {
        $uid    = (int)$uid;
        $target = (int)($kpis[$uid] ?? 0);
        if ($target < 1) continue;
        $stmt->execute([$assignDate, $uid, $user['id'], $target]);
    }

    $pdo->commit();
    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}