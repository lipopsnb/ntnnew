<?php
/**
 * API: Sửa / Xóa chi phí mua vào
 * POST action=update|delete, id, csrf_token
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';

header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']); exit;
}
if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid CSRF token']); exit;
}

$pdo    = getDBConnection();
$user   = currentUser();
$id     = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? 'update';

if (!$id) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu ID']); exit;
}

$row = $pdo->prepare("SELECT * FROM cost_entries WHERE id = ?");
$row->execute([$id]);
$row = $row->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy chi phí']); exit;
}

// ── Kiểm tra quyền ───────────────────────────────────────────
$isToday    = ($row['entry_date'] === date('Y-m-d'));
$isDirector = hasRole('director');

if (!$isToday && !$isDirector) {
    echo json_encode(['ok' => false, 'msg' => 'Chỉ Giám đốc mới được sửa/xóa sau ngày tạo']); exit;
}
if (!hasRole('director','accountant','warehouse','manager')) {
    echo json_encode(['ok' => false, 'msg' => 'Không có quyền']); exit;
}

try {
    $pdo->beginTransaction();

    if ($action === 'delete') {

        $pdo->prepare("
            INSERT INTO audit_log (table_name, record_id, action, changed_by, old_data, note)
            VALUES ('cost_entries', ?, 'delete', ?, ?, ?)
        ")->execute([
            $id, $user['id'],
            json_encode($row, JSON_UNESCAPED_UNICODE),
            'Xóa chi phí'
        ]);

        $pdo->prepare("DELETE FROM cost_entries WHERE id = ?")->execute([$id]);
        $pdo->commit();
        echo json_encode(['ok' => true, 'msg' => 'Đã xóa chi phí']);

    } elseif ($action === 'update') {

        $fields = [
            'entry_date'    => trim($_POST['entry_date']    ?? $row['entry_date']),
            'cost_type'     => trim($_POST['cost_type']     ?? $row['cost_type']),
            'description'   => trim($_POST['description']   ?? $row['description']),
            'supplier_name' => trim($_POST['supplier_name'] ?? $row['supplier_name']),
            'quantity'      => (float)($_POST['quantity']   ?? $row['quantity']),
            'unit'          => trim($_POST['unit']          ?? $row['unit']),
            'unit_price'    => (float)($_POST['unit_price'] ?? $row['unit_price']),
            'total_amount'  => (float)($_POST['total_amount'] ?? $row['total_amount']),
            'invoice_no'    => trim($_POST['invoice_no']    ?? $row['invoice_no']),
            'note'          => trim($_POST['note']          ?? $row['note']),
        ];

        if (!$fields['entry_date'] || !$fields['cost_type'] || !$fields['description']) {
            throw new Exception('Vui lòng nhập đầy đủ thông tin bắt buộc');
        }
        if ($fields['total_amount'] <= 0) {
            throw new Exception('Thành tiền phải lớn hơn 0');
        }

        $pdo->prepare("
            INSERT INTO audit_log
                (table_name, record_id, action, changed_by, old_data, new_data, note)
            VALUES ('cost_entries', ?, 'update', ?, ?, ?, ?)
        ")->execute([
            $id, $user['id'],
            json_encode($row, JSON_UNESCAPED_UNICODE),
            json_encode($fields, JSON_UNESCAPED_UNICODE),
            'Sửa chi phí'
        ]);

        $pdo->prepare("
            UPDATE cost_entries
            SET entry_date = ?, cost_type = ?, description = ?,
                supplier_name = ?, quantity = ?, unit = ?,
                unit_price = ?, total_amount = ?, invoice_no = ?,
                note = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([
            $fields['entry_date'], $fields['cost_type'], $fields['description'],
            $fields['supplier_name'], $fields['quantity'], $fields['unit'],
            $fields['unit_price'], $fields['total_amount'], $fields['invoice_no'],
            $fields['note'], $id
        ]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'msg' => 'Đã cập nhật chi phí']);
    }

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}