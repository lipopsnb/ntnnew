<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';
requireLogin();

header('Content-Type: application/json');

try {
    $pdo = erp_db();
    $items = [];
    $roles = erp_user_roles();
    $isManager = in_array('manager', $roles, true) || in_array('director', $roles, true);
    $isAccountant = in_array('accountant', $roles, true) || in_array('director', $roles, true);

    if ($isManager && erp_table_exists($pdo, 'leave_requests')) {
        $stmt = $pdo->query("SELECT id, employee_name, created_at FROM leave_requests WHERE status = 'pending' ORDER BY created_at DESC LIMIT 5");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = ['icon' => 'fa-regular fa-calendar-xmark', 'title' => 'Đơn nghỉ phép chờ duyệt: ' . ($row['employee_name'] ?: ('#' . $row['id'])), 'url' => erp_url('modules/hr/leave_requests.php'), 'time' => $row['created_at'] ?? ''];
        }
    }

    if ($isManager && erp_table_exists($pdo, 'ot_requests')) {
        $stmt = $pdo->query("SELECT id, employee_name, created_at FROM ot_requests WHERE status = 'pending' ORDER BY created_at DESC LIMIT 5");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = ['icon' => 'fa-regular fa-clock', 'title' => 'Đơn OT chờ duyệt: ' . ($row['employee_name'] ?: ('#' . $row['id'])), 'url' => erp_url('modules/hr/ot_requests.php'), 'time' => $row['created_at'] ?? ''];
        }
    }

    if ($isAccountant && erp_table_exists($pdo, 'invoices')) {
        $stmt = $pdo->query("SELECT id, invoice_code, due_date FROM invoices WHERE total_amount > COALESCE(paid_amount, 0) AND due_date < CURDATE() ORDER BY due_date ASC LIMIT 5");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = ['icon' => 'fa-solid fa-file-invoice-dollar', 'title' => 'Hóa đơn quá hạn: ' . $row['invoice_code'], 'url' => erp_url('modules/invoice/index.php'), 'time' => $row['due_date'] ?? ''];
        }
    }

    if ($isManager && erp_table_exists($pdo, 'assets')) {
        $stmt = $pdo->query("SELECT id, asset_name, maintenance_due_date FROM assets WHERE maintenance_due_date IS NOT NULL AND maintenance_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY maintenance_due_date ASC LIMIT 5");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = ['icon' => 'fa-solid fa-screwdriver-wrench', 'title' => 'Tài sản cần bảo trì: ' . ($row['asset_name'] ?: ('#' . $row['id'])), 'url' => erp_url('modules/assets/index.php'), 'time' => $row['maintenance_due_date'] ?? ''];
        }
    }

    usort($items, static fn(array $a, array $b): int => strcmp((string) ($b['time'] ?? ''), (string) ($a['time'] ?? '')));
    echo json_encode(['count' => count($items), 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode(['count' => 0, 'items' => [], 'message' => $throwable->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
