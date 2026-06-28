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

$id            = (int)($_POST['id'] ?? 0);
$customerCode  = strtoupper(trim($_POST['customer_code'] ?? '')) ?: null;
$customerName  = trim($_POST['customer_name'] ?? '');
$address       = trim($_POST['address'] ?? '') ?: null;
$contactPerson = trim($_POST['contact_person'] ?? '') ?: null;
$phone         = trim($_POST['phone'] ?? '') ?: null;
$email         = trim($_POST['email'] ?? '') ?: null;
$isActive      = isset($_POST['is_active']) ? 1 : 0;

if (!$customerName) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu tên khách hàng']); exit;
}

try {
    if ($id) {
        $stmt = $pdo->prepare("
            UPDATE customers
            SET customer_code=?, customer_name=?, address=?,
                contact_person=?, phone=?, email=?, is_active=?, updated_at=NOW()
            WHERE id=?
        ");
        $stmt->execute([$customerCode, $customerName, $address,
                        $contactPerson, $phone, $email, $isActive, $id]);
        echo json_encode(['ok' => true, 'msg' => 'Đã cập nhật']);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO customers
                (customer_code, customer_name, address, contact_person, phone, email, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$customerCode, $customerName, $address,
                        $contactPerson, $phone, $email, $isActive, $user['id']]);
        echo json_encode(['ok' => true, 'msg' => 'Đã thêm mới', 'id' => $pdo->lastInsertId()]);
    }
} catch (PDOException $e) {
    if ($e->getCode() == 23000) {
        echo json_encode(['ok' => false, 'msg' => 'Mã KH đã tồn tại']);
    } else {
        error_log($e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống']);
    }
}