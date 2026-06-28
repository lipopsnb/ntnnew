<?php
/**
 * API: Tạo mới output cuối ngày
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

$pdo  = getDBConnection();
$user = currentUser();

$receiptId  = (int)($_POST['production_receipt_id'] ?? 0);
$pcId       = (int)($_POST['product_code_id']       ?? 0);
$outputDate = trim($_POST['output_date']             ?? date('Y-m-d'));
$qtyOK      = (float)($_POST['quantity_completed']  ?? 0);
$qtyNG      = (float)($_POST['quantity_defect']     ?? 0);
$note       = trim($_POST['note']                   ?? '');

if (!$receiptId || !$outputDate || ($qtyOK + $qtyNG) <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Vui lòng nhập đầy đủ thông tin']); exit;
}
if ($qtyOK < 0 || $qtyNG < 0) {
    echo json_encode(['ok' => false, 'msg' => 'Số lượng không được âm']); exit;
}

try {
    $pdo->beginTransaction();

    // Lấy thông tin phiếu nhận
    $receipt = $pdo->prepare("
        SELECT pr.*, pc.product_code
        FROM production_receipts pr
        JOIN product_codes pc ON pr.product_code_id = pc.id
        WHERE pr.id = ?
    ");
    $receipt->execute([$receiptId]);
    $receipt = $receipt->fetch(PDO::FETCH_ASSOC);

    if (!$receipt) {
        throw new Exception('Phiếu nhận SX không tồn tại');
    }

    // Kiểm tra còn đủ hàng không
    $reported = $pdo->prepare("
        SELECT COALESCE(SUM(quantity_completed + quantity_defect), 0)
        FROM production_outputs
        WHERE production_receipt_id = ?
    ");
    $reported->execute([$receiptId]);
    $totalReported = (float)$reported->fetchColumn();

    $available = $receipt['quantity_received'] - $totalReported;
    if (($qtyOK + $qtyNG) > $available) {
        throw new Exception(
            "Tổng SL báo cáo (" . ($qtyOK + $qtyNG) . ") vượt quá còn lại ($available)"
        );
    }

    // Tạo số output tự động
    $pcId = $pcId ?: $receipt['product_code_id'];
    $lastNo = $pdo->prepare("
        SELECT output_no FROM production_outputs
        WHERE output_date = ?
        ORDER BY id DESC LIMIT 1
    ");
    $lastNo->execute([$outputDate]);
    $lastNo = $lastNo->fetchColumn();

    if ($lastNo) {
        $seq = (int)substr($lastNo, -3) + 1;
    } else {
        $seq = 1;
    }
    $outputNo = 'OUT-' . date('Ymd', strtotime($outputDate)) . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);

    // Insert production_outputs
    $pdo->prepare("
        INSERT INTO production_outputs
            (output_no, output_date, production_receipt_id, product_code_id,
             quantity_completed, quantity_defect, quantity_delivered,
             note, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, NOW(), NOW())
    ")->execute([
        $outputNo, $outputDate, $receiptId, $pcId,
        $qtyOK, $qtyNG, $note, $user['id']
    ]);

    $outputId = $pdo->lastInsertId();

    // Cập nhật production_stock
    // qty_pending - (qtyOK + qtyNG), qty_completed + qtyOK, qty_defect + qtyNG
    $pdo->prepare("
        INSERT INTO production_stock
            (product_code_id, stock_date, qty_pending, qty_completed, qty_defect)
        VALUES (?, ?, 0, ?, ?)
        ON DUPLICATE KEY UPDATE
            qty_pending   = GREATEST(0, qty_pending - ?),
            qty_completed = qty_completed + ?,
            qty_defect    = qty_defect + ?,
            updated_at    = NOW()
    ")->execute([
        $pcId,
        $outputDate,
        $qtyOK,
        $qtyNG,
        ($qtyOK + $qtyNG),
        $qtyOK,
        $qtyNG
    ]);

    // Ghi audit log
    $pdo->prepare("
        INSERT INTO audit_log
            (table_name, record_id, action, changed_by, new_data, note)
        VALUES ('production_outputs', ?, 'create', ?, ?, ?)
    ")->execute([
        $outputId, $user['id'],
        json_encode([
            'output_no'          => $outputNo,
            'output_date'        => $outputDate,
            'production_receipt_id' => $receiptId,
            'quantity_completed' => $qtyOK,
            'quantity_defect'    => $qtyNG,
        ], JSON_UNESCAPED_UNICODE),
        'Tạo output ' . $outputNo
    ]);

    $pdo->commit();
    echo json_encode([
        'ok'  => true,
        'msg' => 'Đã lưu output ' . $outputNo,
        'id'  => $outputId,
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}