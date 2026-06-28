<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

requireLogin();
header('Content-Type: application/json');

if (!erp_has_any_role(['director', 'manager', 'production', 'warehouse'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Bạn không có quyền cập nhật trạng thái.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Phương thức không hợp lệ.']);
    exit;
}

if (!erp_validate_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'CSRF token không hợp lệ.']);
    exit;
}

$jobOrderId = (int) ($_POST['job_order_id'] ?? 0);
$newStatus = trim((string) ($_POST['new_status'] ?? ''));
$allowedStatuses = ['draft', 'in_progress', 'done', 'delivered', 'cancelled'];
$transitions = [
    'draft' => ['draft', 'in_progress', 'cancelled'],
    'in_progress' => ['in_progress', 'done', 'cancelled'],
    'done' => ['done', 'delivered'],
    'delivered' => ['delivered'],
    'cancelled' => ['cancelled'],
];

if ($jobOrderId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Dữ liệu cập nhật không hợp lệ.']);
    exit;
}

try {
    $pdo = erp_db();
    $stmt = $pdo->prepare('SELECT status FROM job_orders WHERE id = ? LIMIT 1');
    $stmt->execute([$jobOrderId]);
    $currentStatus = $stmt->fetchColumn();
    if (!$currentStatus) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy phiếu gia công.']);
        exit;
    }
    if (!in_array($newStatus, $transitions[(string) $currentStatus] ?? [], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Không thể chuyển từ ' . erp_status_label((string) $currentStatus) . ' sang ' . erp_status_label($newStatus) . '.']);
        exit;
    }

    $updateStmt = $pdo->prepare('UPDATE job_orders SET status = ?, updated_at = NOW() WHERE id = ?');
    $updateStmt->execute([$newStatus, $jobOrderId]);
    echo json_encode(['success' => true, 'message' => 'Cập nhật trạng thái thành công.']);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $throwable->getMessage()]);
}
