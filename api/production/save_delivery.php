<?php
/**
 * API: Tạo biên bản giao hàng
 * POST: delivery_date, customer_id, note, status,
 *       items[n][production_output_id], items[n][product_code_id],
 *       items[n][quantity], items[n][unit_price], items[n][total_price],
 *       items[n][description], items[n][unit]
 * → Tạo delivery_notes + delivery_note_items
 * → Trừ production_stock.qty_completed
 * → Cập nhật production_outputs.quantity_delivered
 * → Ghi warehouse_stock_log
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';

header('Content-Type: application/json');
requireLogin();
requireRole('director','accountant','warehouse','production','manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']); exit;
}
if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid CSRF token']); exit;
}

$pdo  = getDBConnection();
$user = currentUser();

$deliveryDate = trim($_POST['delivery_date'] ?? '');
$customerId   = (int)($_POST['customer_id']  ?? 0);
$note         = trim($_POST['note']          ?? '');
$status       = in_array($_POST['status'] ?? '', ['draft','confirmed']) ? $_POST['status'] : 'draft';
$items        = $_POST['items'] ?? [];

if (!$deliveryDate || !$customerId || empty($items)) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu thông tin bắt buộc']); exit;
}

// Lọc dòng hợp lệ
$validItems = [];
foreach ($items as $it) {
    $outputId = (int)($it['production_output_id'] ?? 0);
    $pcId     = (int)($it['product_code_id']      ?? 0);
    $qty      = (float)($it['quantity']           ?? 0);
    $price    = (float)($it['unit_price']         ?? 0);
    $total    = (float)($it['total_price']        ?? 0);
    if ($outputId && $pcId && $qty > 0) {
        $validItems[] = [
            'output_id'   => $outputId,
            'pc_id'       => $pcId,
            'qty'         => $qty,
            'price'       => $price,
            'total'       => $total ?: round($qty * $price),
            'description' => trim($it['description'] ?? ''),
            'unit'        => trim($it['unit']        ?? ''),
        ];
    }
}
if (empty($validItems)) {
    echo json_encode(['ok' => false, 'msg' => 'Không có dòng sản phẩm hợp lệ']); exit;
}

try {
    $pdo->beginTransaction();

    // Sinh số biên bản: DN-YYYYMMDD-XXXX
    $prefix  = 'DN-' . date('Ymd', strtotime($deliveryDate)) . '-';
    $lastNo  = $pdo->prepare("
        SELECT delivery_no FROM delivery_notes
        WHERE delivery_no LIKE ? ORDER BY id DESC LIMIT 1
    ");
    $lastNo->execute([$prefix . '%']);
    $lastRow    = $lastNo->fetchColumn();
    $seq        = $lastRow ? ((int)substr($lastRow, -4) + 1) : 1;
    $deliveryNo = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    $grandTotal = array_sum(array_column($validItems, 'total'));

    // 1. Tạo delivery_notes
    $pdo->prepare("
        INSERT INTO delivery_notes
            (delivery_no, delivery_date, customer_id, total_amount, status, note, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $deliveryNo, $deliveryDate, $customerId,
        $grandTotal, $status, $note, $user['id']
    ]);
    $noteId = $pdo->lastInsertId();

    foreach ($validItems as $it) {
        // Kiểm tra output còn đủ hàng (qty_completed - đã giao)
        $chk = $pdo->prepare("
            SELECT po.quantity_completed,
                   po.product_code_id,
                   COALESCE(SUM(dni.quantity),0) AS delivered
            FROM production_outputs po
            LEFT JOIN delivery_note_items dni ON dni.production_output_id = po.id
            WHERE po.id = ?
            GROUP BY po.id
            FOR UPDATE
        ");
        $chk->execute([$it['output_id']]);
        $outRow = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$outRow) {
            throw new Exception("Output #" . $it['output_id'] . " không tồn tại");
        }
        $avail = $outRow['quantity_completed'] - $outRow['delivered'];
        if ($it['qty'] > $avail) {
            throw new Exception(
                "Output #" . $it['output_id'] .
                ": SL giao ({$it['qty']}) vượt quá còn lại ($avail)"
            );
        }

        // 2. Tạo delivery_note_items
        $pdo->prepare("
            INSERT INTO delivery_note_items
                (delivery_note_id, production_output_id, product_code_id,
                 description, unit, quantity, unit_price, total_price)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $noteId, $it['output_id'], $it['pc_id'],
            $it['description'], $it['unit'],
            $it['qty'], $it['price'], $it['total']
        ]);

        // 3. Cập nhật production_outputs.quantity_delivered
        $pdo->prepare("
            UPDATE production_outputs
            SET quantity_delivered = quantity_delivered + ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$it['qty'], $it['output_id']]);

        // 4. Trừ production_stock.qty_completed
        $pdo->prepare("
            UPDATE production_stock
            SET qty_completed = GREATEST(0, qty_completed - ?)
            WHERE product_code_id = ? AND stock_date = ?
        ")->execute([$it['qty'], $it['pc_id'], $deliveryDate]);

        // 5. Ghi log delivery (âm)
        $pdo->prepare("
            INSERT INTO warehouse_stock_log
                (product_code_id, log_date, txn_type, stock_type,
                 qty_change, ref_table, ref_id, note, created_by)
            VALUES (?, ?, 'delivery', 'completed', ?, 'delivery_notes', ?, ?, ?)
        ")->execute([
            $it['pc_id'], $deliveryDate, -$it['qty'],
            $noteId, "Giao hàng: $deliveryNo", $user['id']
        ]);
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'delivery_no' => $deliveryNo]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}