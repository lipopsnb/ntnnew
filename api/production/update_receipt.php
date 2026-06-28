<?php
/**
 * API: Sửa / Xóa phiếu nhận SX từ kho
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

$row = $pdo->prepare("SELECT * FROM production_receipts WHERE id = ?");
$row->execute([$id]);
$row = $row->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy phiếu nhận']); exit;
}

// ── Kiểm tra quyền ───────────────────────────────────────────
$isToday    = ($row['receipt_date'] === date('Y-m-d'));
$isDirector = hasRole('director');

if (!$isToday && !$isDirector) {
    echo json_encode(['ok' => false, 'msg' => 'Chỉ Giám đốc mới được sửa/xóa sau ngày tạo']); exit;
}
if (!hasRole('director','accountant','warehouse','production','manager')) {
    echo json_encode(['ok' => false, 'msg' => 'Không có quyền']); exit;
}

try {
    $pdo->beginTransaction();

    if ($action === 'delete') {

        // Kiểm tra ràng buộc: đã có production_outputs chưa
        $linked = $pdo->prepare("
            SELECT COUNT(*) FROM production_outputs WHERE production_receipt_id = ?
        ");
        $linked->execute([$id]);
        if ($linked->fetchColumn() > 0) {
            throw new Exception('Không thể xóa: đã có output SX liên kết. Xóa output trước.');
        }

        // Hoàn lại warehouse_stock.qty_pending
        $pdo->prepare("
            UPDATE warehouse_stock
            SET qty_pending = qty_pending + ?
            WHERE product_code_id = ?
        ")->execute([$row['quantity_received'], $row['product_code_id']]);

        // Trừ production_stock.qty_pending
        $pdo->prepare("
            UPDATE production_stock
            SET qty_pending = GREATEST(0, qty_pending - ?)
            WHERE product_code_id = ? AND stock_date = ?
        ")->execute([$row['quantity_received'], $row['product_code_id'], $row['receipt_date']]);

        // Hoàn lại quantity_sent trong warehouse_imports
        $pdo->prepare("
            UPDATE warehouse_imports
            SET quantity_sent = GREATEST(0, quantity_sent - ?),
                status = CASE
                    WHEN (quantity_sent - ?) <= 0 THEN 'pending'
                    WHEN (quantity_sent - ?) < quantity THEN 'partial'
                    ELSE 'completed'
                END,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([
            $row['quantity_received'],
            $row['quantity_received'],
            $row['quantity_received'],
            $row['warehouse_import_id']
        ]);

        // Ghi audit log
        $pdo->prepare("
            INSERT INTO audit_log (table_name, record_id, action, changed_by, old_data, note)
            VALUES ('production_receipts', ?, 'delete', ?, ?, ?)
        ")->execute([
            $id, $user['id'],
            json_encode($row, JSON_UNESCAPED_UNICODE),
            'Xóa phiếu nhận ' . $row['receipt_no']
        ]);

        $pdo->prepare("DELETE FROM production_receipts WHERE id = ?")->execute([$id]);
        $pdo->commit();
        echo json_encode(['ok' => true, 'msg' => 'Đã xóa phiếu nhận ' . $row['receipt_no']]);

    } elseif ($action === 'update') {

        $newImportId = (int)($_POST['warehouse_import_id'] ?? $row['warehouse_import_id']);
        $newQty      = (float)($_POST['quantity_received'] ?? $row['quantity_received']);
        $newNote     = trim($_POST['note'] ?? $row['note']);

        if (!$newImportId || $newQty <= 0) {
            throw new Exception('Vui lòng nhập đầy đủ thông tin');
        }

        // Kiểm tra đã có output chưa — nếu có thì qty không được < tổng output
        $usedQty = $pdo->prepare("
            SELECT COALESCE(SUM(quantity_completed + quantity_defect), 0)
            FROM production_outputs WHERE production_receipt_id = ?
        ");
        $usedQty->execute([$id]);
        $used = (float)$usedQty->fetchColumn();

        if ($newQty < $used) {
            throw new Exception(
                "Số lượng mới ($newQty) không được nhỏ hơn đã output ($used)"
            );
        }

        // Lấy phiếu import mới để validate
        $newImport = $pdo->prepare("
            SELECT id, quantity, quantity_sent, product_code_id
            FROM warehouse_imports WHERE id = ?
        ");
        $newImport->execute([$newImportId]);
        $newImport = $newImport->fetch(PDO::FETCH_ASSOC);
        if (!$newImport) {
            throw new Exception('Phiếu nhập kho không hợp lệ');
        }

        $qtyDiff = $newQty - $row['quantity_received'];

        // Điều chỉnh warehouse_stock và production_stock
        if ($qtyDiff != 0 || $newImportId !== (int)$row['warehouse_import_id']) {
            // Hoàn lại stock cũ
            $pdo->prepare("
                UPDATE warehouse_stock
                SET qty_pending = qty_pending + ?
                WHERE product_code_id = ?
            ")->execute([$row['quantity_received'], $row['product_code_id']]);

            $pdo->prepare("
                UPDATE production_stock
                SET qty_pending = GREATEST(0, qty_pending - ?)
                WHERE product_code_id = ? AND stock_date = ?
            ")->execute([$row['quantity_received'], $row['product_code_id'], $row['receipt_date']]);

            // Hoàn quantity_sent import cũ
            $pdo->prepare("
                UPDATE warehouse_imports
                SET quantity_sent = GREATEST(0, quantity_sent - ?),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$row['quantity_received'], $row['warehouse_import_id']]);

            // Kiểm tra import mới còn đủ không
            $availableNew = $newImport['quantity'] - $newImport['quantity_sent'];
            // Nếu cùng import thì phải cộng lại phần vừa hoàn
            if ($newImportId === (int)$row['warehouse_import_id']) {
                $availableNew += $row['quantity_received'];
            }
            if ($newQty > $availableNew) {
                throw new Exception(
                    "Phiếu nhập không đủ hàng. Còn: " . number_format($availableNew)
                );
            }

            // Trừ warehouse_stock mới
            $pdo->prepare("
                UPDATE warehouse_stock
                SET qty_pending = GREATEST(0, qty_pending - ?)
                WHERE product_code_id = ?
            ")->execute([$newQty, $newImport['product_code_id']]);

            // Cộng production_stock mới
            $pdo->prepare("
                INSERT INTO production_stock (product_code_id, stock_date, qty_pending)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE qty_pending = qty_pending + VALUES(qty_pending)
            ")->execute([$newImport['product_code_id'], $row['receipt_date'], $newQty]);

            // Cập nhật quantity_sent import mới
            $pdo->prepare("
                UPDATE warehouse_imports
                SET quantity_sent = quantity_sent + ?,
                    status = CASE
                        WHEN (quantity_sent + ?) >= quantity THEN 'completed'
                        WHEN (quantity_sent + ?) > 0 THEN 'partial'
                        ELSE 'pending'
                    END,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$newQty, $newQty, $newQty, $newImportId]);
        }

        // Ghi audit log
        $pdo->prepare("
            INSERT INTO audit_log
                (table_name, record_id, action, changed_by, old_data, new_data, note)
            VALUES ('production_receipts', ?, 'update', ?, ?, ?, ?)
        ")->execute([
            $id, $user['id'],
            json_encode($row, JSON_UNESCAPED_UNICODE),
            json_encode([
                'warehouse_import_id' => $newImportId,
                'quantity_received'   => $newQty,
                'note'                => $newNote,
            ], JSON_UNESCAPED_UNICODE),
            'Sửa phiếu nhận ' . $row['receipt_no']
        ]);

        $pdo->prepare("
            UPDATE production_receipts
            SET warehouse_import_id = ?, quantity_received = ?,
                note = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$newImportId, $newQty, $newNote, $id]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'msg' => 'Đã cập nhật phiếu nhận']);
    }

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}