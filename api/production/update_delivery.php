<?php
/**
 * API: Sửa / Xóa biên bản giao h��ng
 * POST action=update|delete, id, csrf_token
 * Sửa: header (delivery_date, customer_id, note) + items[]
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

$row = $pdo->prepare("SELECT * FROM delivery_notes WHERE id = ?");
$row->execute([$id]);
$row = $row->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy biên bản']); exit;
}

// ── Kiểm tra quyền ───────────────────────────────────────────
$isToday    = ($row['delivery_date'] === date('Y-m-d'));
$isDirector = hasRole('director');

if (!$isToday && !$isDirector) {
    echo json_encode(['ok' => false, 'msg' => 'Chỉ Giám đốc mới được sửa/xóa sau ngày giao']); exit;
}

// Chỉ sửa được draft hoặc confirmed (không sửa invoiced trừ director)
if ($row['status'] === 'invoiced' && !$isDirector) {
    echo json_encode(['ok' => false, 'msg' => 'Biên bản đã xuất hoá đơn, không thể sửa']); exit;
}

try {
    $pdo->beginTransaction();

    // Lấy items cũ
    $oldItems = $pdo->prepare("SELECT * FROM delivery_note_items WHERE delivery_note_id = ?");
    $oldItems->execute([$id]);
    $oldItems = $oldItems->fetchAll(PDO::FETCH_ASSOC);

    if ($action === 'delete') {

        // Hoàn lại production_stock.qty_completed cho từng item
        foreach ($oldItems as $it) {
            $pdo->prepare("
                UPDATE production_stock
                SET qty_completed = qty_completed + ?
                WHERE product_code_id = ? AND stock_date = ?
            ")->execute([$it['quantity'], $it['product_code_id'], $row['delivery_date']]);

            // Trừ lại quantity_delivered trong production_outputs
            $pdo->prepare("
                UPDATE production_outputs
                SET quantity_delivered = GREATEST(0, quantity_delivered - ?),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$it['quantity'], $it['production_output_id']]);
        }

        // Ghi audit log
        $pdo->prepare("
            INSERT INTO audit_log (table_name, record_id, action, changed_by, old_data, note)
            VALUES ('delivery_notes', ?, 'delete', ?, ?, ?)
        ")->execute([
            $id, $user['id'],
            json_encode(array_merge($row, ['items' => $oldItems]), JSON_UNESCAPED_UNICODE),
            'Xóa biên bản ' . $row['delivery_no']
        ]);

        $pdo->prepare("DELETE FROM delivery_note_items WHERE delivery_note_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM delivery_notes WHERE id = ?")->execute([$id]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'msg' => 'Đã xóa biên bản ' . $row['delivery_no']]);

    } elseif ($action === 'update') {

        $newDate     = trim($_POST['delivery_date'] ?? $row['delivery_date']);
        $newCustId   = (int)($_POST['customer_id']  ?? $row['customer_id']);
        $newNote     = trim($_POST['note']           ?? $row['note']);
        $newStatus   = in_array($_POST['status'] ?? '', ['draft','confirmed','invoiced'])
                        ? $_POST['status'] : $row['status'];
        $newItems    = $_POST['items'] ?? [];

        if (!$newDate || !$newCustId) {
            throw new Exception('Vui lòng nhập đầy đủ thông tin');
        }

        // Validate items mới
        $validItems = [];
        foreach ($newItems as $it) {
            $outputId = (int)($it['production_output_id'] ?? 0);
            $pcId     = (int)($it['product_code_id']      ?? 0);
            $qty      = (float)($it['quantity']           ?? 0);
            $price    = (float)($it['unit_price']         ?? 0);
            $total    = (float)($it['total_price']        ?? round($qty * $price));
            if ($outputId && $pcId && $qty > 0) {
                $validItems[] = [
                    'output_id'   => $outputId,
                    'pc_id'       => $pcId,
                    'qty'         => $qty,
                    'price'       => $price,
                    'total'       => $total,
                    'description' => trim($it['description'] ?? ''),
                    'unit'        => trim($it['unit']        ?? ''),
                ];
            }
        }
        if (empty($validItems)) {
            throw new Exception('Cần ít nhất 1 dòng sản phẩm hợp lệ');
        }

        // Hoàn lại stock từ items cũ
        foreach ($oldItems as $it) {
            $pdo->prepare("
                UPDATE production_stock
                SET qty_completed = qty_completed + ?
                WHERE product_code_id = ? AND stock_date = ?
            ")->execute([$it['quantity'], $it['product_code_id'], $row['delivery_date']]);

            $pdo->prepare("
                UPDATE production_outputs
                SET quantity_delivered = GREATEST(0, quantity_delivered - ?),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$it['quantity'], $it['production_output_id']]);
        }

        // Xóa items cũ
        $pdo->prepare("DELETE FROM delivery_note_items WHERE delivery_note_id = ?")->execute([$id]);

        // Thêm items mới + trừ stock
        $grandTotal = 0;
        foreach ($validItems as $it) {
            // Kiểm tra output còn đủ không
            $chk = $pdo->prepare("
                SELECT po.quantity_completed,
                       po.product_code_id,
                       COALESCE(SUM(dni.quantity),0) AS delivered
                FROM production_outputs po
                LEFT JOIN delivery_note_items dni ON dni.production_output_id = po.id
                WHERE po.id = ?
                GROUP BY po.id
            ");
            $chk->execute([$it['output_id']]);
            $outRow = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$outRow) {
                throw new Exception("Output #{$it['output_id']} không tồn tại");
            }
            $avail = $outRow['quantity_completed'] - $outRow['delivered'];
            if ($it['qty'] > $avail) {
                throw new Exception(
                    "Output #{$it['output_id']}: SL giao ({$it['qty']}) vượt quá còn lại ($avail)"
                );
            }

            $pdo->prepare("
                INSERT INTO delivery_note_items
                    (delivery_note_id, production_output_id, product_code_id,
                     description, unit, quantity, unit_price, total_price)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $id, $it['output_id'], $it['pc_id'],
                $it['description'], $it['unit'],
                $it['qty'], $it['price'], $it['total']
            ]);

            // Trừ production_stock
            $pdo->prepare("
                UPDATE production_stock
                SET qty_completed = GREATEST(0, qty_completed - ?)
                WHERE product_code_id = ? AND stock_date = ?
            ")->execute([$it['qty'], $it['pc_id'], $newDate]);

            // Cập nhật quantity_delivered
            $pdo->prepare("
                UPDATE production_outputs
                SET quantity_delivered = quantity_delivered + ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$it['qty'], $it['output_id']]);

            $grandTotal += $it['total'];
        }

        // Ghi audit log
        $pdo->prepare("
            INSERT INTO audit_log
                (table_name, record_id, action, changed_by, old_data, new_data, note)
            VALUES ('delivery_notes', ?, 'update', ?, ?, ?, ?)
        ")->execute([
            $id, $user['id'],
            json_encode(array_merge($row, ['items' => $oldItems]), JSON_UNESCAPED_UNICODE),
            json_encode([
                'delivery_date' => $newDate,
                'customer_id'   => $newCustId,
                'note'          => $newNote,
                'items'         => $validItems,
            ], JSON_UNESCAPED_UNICODE),
            'Sửa biên bản ' . $row['delivery_no']
        ]);

        // Cập nhật header
        $pdo->prepare("
            UPDATE delivery_notes
            SET delivery_date = ?, customer_id = ?, note = ?,
                status = ?, total_amount = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$newDate, $newCustId, $newNote, $newStatus, $grandTotal, $id]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'msg' => 'Đã cập nhật biên bản giao hàng']);
    }

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}