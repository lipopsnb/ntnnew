<?php
/**
 * API: Sửa / Xóa output SX cuối ngày
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

$row = $pdo->prepare("SELECT * FROM production_outputs WHERE id = ?");
$row->execute([$id]);
$row = $row->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy output']); exit;
}

// ── Kiểm tra quyền ───────────────────────────────────────────
$isToday    = ($row['output_date'] === date('Y-m-d'));
$isDirector = hasRole('director');

if (!$isToday && !$isDirector) {
    echo json_encode(['ok' => false, 'msg' => 'Chỉ Giám đốc mới được sửa/xóa sau ngày tạo']); exit;
}

try {
    $pdo->beginTransaction();

    if ($action === 'delete') {

        // Kiểm tra ràng buộc delivery
        $linked = $pdo->prepare("
            SELECT COUNT(*) FROM delivery_note_items WHERE production_output_id = ?
        ");
        $linked->execute([$id]);
        if ($linked->fetchColumn() > 0) {
            throw new Exception('Không thể xóa: đã có biên bản giao hàng liên kết. Xóa biên bản trước.');
        }

        // Hoàn lại production_stock
        $pdo->prepare("
            UPDATE production_stock
            SET qty_completed = GREATEST(0, qty_completed - ?),
                qty_defect    = GREATEST(0, qty_defect - ?),
                qty_pending   = qty_pending + ?
            WHERE product_code_id = ? AND stock_date = ?
        ")->execute([
            $row['quantity_completed'],
            $row['quantity_defect'],
            $row['quantity_completed'] + $row['quantity_defect'],
            $row['product_code_id'],
            $row['output_date']
        ]);

        // Ghi audit log
        $pdo->prepare("
            INSERT INTO audit_log (table_name, record_id, action, changed_by, old_data, note)
            VALUES ('production_outputs', ?, 'delete', ?, ?, ?)
        ")->execute([
            $id, $user['id'],
            json_encode($row, JSON_UNESCAPED_UNICODE),
            'Xóa output ' . $row['output_no']
        ]);

        $pdo->prepare("DELETE FROM production_outputs WHERE id = ?")->execute([$id]);
        $pdo->commit();
        echo json_encode(['ok' => true, 'msg' => 'Đã xóa output ' . $row['output_no']]);

    } elseif ($action === 'update') {

        $newCompleted = (float)($_POST['quantity_completed'] ?? $row['quantity_completed']);
        $newDefect    = (float)($_POST['quantity_defect']    ?? $row['quantity_defect']);
        $newNote      = trim($_POST['note']                  ?? $row['note']);

        if ($newCompleted < 0 || $newDefect < 0) {
            throw new Exception('Số lượng không được âm');
        }

        // Kiểm tra completed >= đã giao
        $delivered = $pdo->prepare("
            SELECT COALESCE(SUM(quantity), 0)
            FROM delivery_note_items WHERE production_output_id = ?
        ");
        $delivered->execute([$id]);
        $deliveredQty = (float)$delivered->fetchColumn();

        if ($newCompleted < $deliveredQty) {
            throw new Exception(
                "SL hoàn thành mới ($newCompleted) không được nhỏ hơn đã giao ($deliveredQty)"
            );
        }

        // Kiểm tra tổng output không vượt quantity_received
        $receipt = $pdo->prepare("
            SELECT pr.quantity_received,
                   COALESCE(SUM(po.quantity_completed + po.quantity_defect), 0) AS total_reported
            FROM production_receipts pr
            LEFT JOIN production_outputs po
                ON po.production_receipt_id = pr.id AND po.id != ?
            WHERE pr.id = ?
            GROUP BY pr.id
        ");
        $receipt->execute([$id, $row['production_receipt_id']]);
        $receiptRow = $receipt->fetch(PDO::FETCH_ASSOC);
        $maxAllowed = $receiptRow['quantity_received'] - $receiptRow['total_reported'];

        if (($newCompleted + $newDefect) > $maxAllowed) {
            throw new Exception(
                "Tổng SL báo cáo vượt quá giới hạn phiếu nhận. Tối đa: $maxAllowed"
            );
        }

        // Điều chỉnh production_stock
        $diffCompleted = $newCompleted - $row['quantity_completed'];
        $diffDefect    = $newDefect    - $row['quantity_defect'];
        $diffTotal     = $diffCompleted + $diffDefect;

        $pdo->prepare("
            UPDATE production_stock
            SET qty_completed = GREATEST(0, qty_completed + ?),
                qty_defect    = GREATEST(0, qty_defect + ?),
                qty_pending   = GREATEST(0, qty_pending - ?)
            WHERE product_code_id = ? AND stock_date = ?
        ")->execute([
            $diffCompleted, $diffDefect, $diffTotal,
            $row['product_code_id'], $row['output_date']
        ]);

        // Ghi audit log
        $pdo->prepare("
            INSERT INTO audit_log
                (table_name, record_id, action, changed_by, old_data, new_data, note)
            VALUES ('production_outputs', ?, 'update', ?, ?, ?, ?)
        ")->execute([
            $id, $user['id'],
            json_encode($row, JSON_UNESCAPED_UNICODE),
            json_encode([
                'quantity_completed' => $newCompleted,
                'quantity_defect'    => $newDefect,
                'note'               => $newNote,
            ], JSON_UNESCAPED_UNICODE),
            'Sửa output ' . $row['output_no']
        ]);

        $pdo->prepare("
            UPDATE production_outputs
            SET quantity_completed = ?, quantity_defect = ?,
                note = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$newCompleted, $newDefect, $newNote, $id]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'msg' => 'Đã cập nhật output']);
    }

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}