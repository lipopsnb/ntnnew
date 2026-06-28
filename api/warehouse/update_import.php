<?php
/**
 * API: Sửa / Xóa phiếu nhập kho
 * POST action=update: import_date, product_code_id, quantity, note, csrf_token
 * POST action=delete: csrf_token
 * GET  id=xxx
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

// Lấy bản ghi gốc
$row = $pdo->prepare("
    SELECT wi.*, pc.product_code
    FROM warehouse_imports wi
    JOIN product_codes pc ON wi.product_code_id = pc.id
    WHERE wi.id = ?
");
$row->execute([$id]);
$row = $row->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy phiếu nhập']); exit;
}

// ── Kiểm tra quyền sửa/xóa ───────────────────────────────────
$isToday    = ($row['import_date'] === date('Y-m-d'));
$isDirector = hasRole('director');

if (!$isToday && !$isDirector) {
    echo json_encode(['ok' => false, 'msg' => 'Chỉ Giám đốc mới được sửa/xóa sau ngày tạo']); exit;
}
if (!hasRole('director','accountant','warehouse','manager')) {
    echo json_encode(['ok' => false, 'msg' => 'Không có quyền']); exit;
}

try {
    $pdo->beginTransaction();

    // ════════════════════════════════════════
    if ($action === 'delete') {
    // ════════════════════════════════════════

        // Kiểm tra ràng buộc: đã có production_receipts chưa
        $linked = $pdo->prepare("
            SELECT COUNT(*) FROM production_receipts WHERE warehouse_import_id = ?
        ");
        $linked->execute([$id]);
        if ($linked->fetchColumn() > 0) {
            throw new Exception('Không thể xóa: đã có phiếu nhận SX liên kết. Xóa phiếu nhận trước.');
        }

        // Hoàn lại warehouse_stock.qty_pending
        $pdo->prepare("
            UPDATE warehouse_stock
            SET qty_pending = GREATEST(0, qty_pending - ?)
            WHERE product_code_id = ?
        ")->execute([$row['quantity'], $row['product_code_id']]);

        // Ghi audit log
        $pdo->prepare("
            INSERT INTO audit_log (table_name, record_id, action, changed_by, old_data, note)
            VALUES ('warehouse_imports', ?, 'delete', ?, ?, ?)
        ")->execute([
            $id, $user['id'],
            json_encode($row, JSON_UNESCAPED_UNICODE),
            'Xóa phiếu nhập ' . $row['import_no']
        ]);

        // Xóa warehouse_stock_log liên quan
        $pdo->prepare("
            DELETE FROM warehouse_stock_log WHERE ref_table = 'warehouse_imports' AND ref_id = ?
        ")->execute([$id]);

        // Xóa bản ghi
        $pdo->prepare("DELETE FROM warehouse_imports WHERE id = ?")->execute([$id]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'msg' => 'Đã xóa phiếu nhập ' . $row['import_no']]);

    // ════════════════════════════════════════
    } elseif ($action === 'update') {
    // ════════════════════════════════════════

        $newDate    = trim($_POST['import_date']     ?? $row['import_date']);
        $newPcId    = (int)($_POST['product_code_id'] ?? $row['product_code_id']);
        $newQty     = (float)($_POST['quantity']      ?? $row['quantity']);
        $newNote    = trim($_POST['note']             ?? $row['note']);

        if (!$newDate || !$newPcId || $newQty <= 0) {
            throw new Exception('Vui lòng nhập đầy đủ thông tin');
        }

        // Validate: qty mới không được < quantity_sent
        if ($newQty < $row['quantity_sent']) {
            throw new Exception(
                "Số lượng mới ($newQty) không được nhỏ hơn đã chuyển SX ({$row['quantity_sent']})"
            );
        }

        $qtyDiff = $newQty - $row['quantity'];  // Dương: tăng, Âm: giảm

        // Cập nhật warehouse_stock
        if ($qtyDiff != 0) {
            $pdo->prepare("
                UPDATE warehouse_stock
                SET qty_pending = GREATEST(0, qty_pending + ?)
                WHERE product_code_id = ?
            ")->execute([$qtyDiff, $row['product_code_id']]);

            // Nếu đổi product_code_id thì hoàn cũ, cộng mới
            if ($newPcId !== (int)$row['product_code_id']) {
                // Hoàn lại qty cũ cho pc cũ
                $pdo->prepare("
                    UPDATE warehouse_stock
                    SET qty_pending = GREATEST(0, qty_pending - ?)
                    WHERE product_code_id = ?
                ")->execute([$newQty, $row['product_code_id']]);
                // Cộng qty mới cho pc mới
                $pdo->prepare("
                    INSERT INTO warehouse_stock (product_code_id, qty_pending)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE qty_pending = qty_pending + VALUES(qty_pending)
                ")->execute([$newPcId, $newQty]);
            }
        }

        // Tính lại status
        $newStatus = 'pending';
        if ($row['quantity_sent'] > 0) {
            $newStatus = ($row['quantity_sent'] >= $newQty) ? 'completed' : 'partial';
        }

        // Ghi audit log
        $newData = [
            'import_date'     => $newDate,
            'product_code_id' => $newPcId,
            'quantity'        => $newQty,
            'note'            => $newNote,
        ];
        $pdo->prepare("
            INSERT INTO audit_log
                (table_name, record_id, action, changed_by, old_data, new_data, note)
            VALUES ('warehouse_imports', ?, 'update', ?, ?, ?, ?)
        ")->execute([
            $id, $user['id'],
            json_encode($row, JSON_UNESCAPED_UNICODE),
            json_encode($newData, JSON_UNESCAPED_UNICODE),
            'Sửa phiếu nhập ' . $row['import_no']
        ]);

        // Cập nhật bản ghi
        $pdo->prepare("
            UPDATE warehouse_imports
            SET import_date = ?, product_code_id = ?, quantity = ?,
                note = ?, status = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$newDate, $newPcId, $newQty, $newNote, $newStatus, $id]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'msg' => 'Đã cập nhật phiếu nhập']);
    }

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}