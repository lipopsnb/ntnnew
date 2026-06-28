<?php
/**
 * API: Tạo mới output cuối ngày
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';

header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

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
$qtyNG      = (float)($_POST['quantity_defect']      ?? 0);
$note       = trim($_POST['note']                    ?? '');

if (!$receiptId || !$outputDate) {
    echo json_encode(['ok' => false, 'msg' => 'Vui lòng nhập đầy đủ thông tin']); exit;
}
if (($qtyOK + $qtyNG) <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Tổng số lượng phải lớn hơn 0']); exit;
}
if ($qtyOK < 0 || $qtyNG < 0) {
    echo json_encode(['ok' => false, 'msg' => 'Số lượng không được âm']); exit;
}

try {
    $pdo->beginTransaction();

    // Lấy thông tin phiếu nhận
    $stmt = $pdo->prepare("
        SELECT pr.*, pc.product_code
        FROM production_receipts pr
        JOIN product_codes pc ON pr.product_code_id = pc.id
        WHERE pr.id = ?
    ");
    $stmt->execute([$receiptId]);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$receipt) {
        throw new Exception('Phiếu nhận SX không tồn tại');
    }

    // Kiểm tra còn đủ hàng không
    $stmt2 = $pdo->prepare("
        SELECT COALESCE(SUM(quantity_completed + quantity_defect), 0)
        FROM production_outputs
        WHERE production_receipt_id = ?
    ");
    $stmt2->execute([$receiptId]);
    $totalReported = (float)$stmt2->fetchColumn();

    $available = (float)$receipt['quantity_received'] - $totalReported;
    if (($qtyOK + $qtyNG) > $available) {
        throw new Exception(
            "Tổng SL báo cáo (" . number_format($qtyOK + $qtyNG) .
            ") vượt quá còn lại (" . number_format($available) . ")"
        );
    }

    // Tạo số output tự động
    $realPcId = $pcId ?: (int)$receipt['product_code_id'];

    $stmt3 = $pdo->prepare("
        SELECT output_no FROM production_outputs
        WHERE DATE(output_date) = ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt3->execute([$outputDate]);
    $lastNo = $stmt3->fetchColumn();

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
        $outputNo, $outputDate, $receiptId, $realPcId,
        $qtyOK, $qtyNG,
        $note, $user['id']
    ]);

    $outputId = (int)$pdo->lastInsertId();

    // Cập nhật production_stock: kiểm tra trước rồi INSERT hoặc UPDATE
    $checkStock = $pdo->prepare("
        SELECT id FROM production_stock
        WHERE product_code_id = ? AND stock_date = ?
    ");
    $checkStock->execute([$realPcId, $outputDate]);
    $stockExists = $checkStock->fetchColumn();

    if ($stockExists) {
        $pdo->prepare("
            UPDATE production_stock
            SET qty_pending   = GREATEST(0, qty_pending - ?),
                qty_completed = qty_completed + ?,
                qty_defect    = qty_defect + ?,
                updated_at    = NOW()
            WHERE product_code_id = ? AND stock_date = ?
        ")->execute([
            ($qtyOK + $qtyNG),
            $qtyOK,
            $qtyNG,
            $realPcId,
            $outputDate
        ]);
    } else {
        $pdo->prepare("
            INSERT INTO production_stock
                (product_code_id, stock_date, qty_pending, qty_completed, qty_defect, updated_at)
            VALUES (?, ?, 0, ?, ?, NOW())
        ")->execute([
            $realPcId,
            $outputDate,
            $qtyOK,
            $qtyNG
        ]);
    }

    // Ghi audit log
    $pdo->prepare("
        INSERT INTO audit_log
            (table_name, record_id, action, changed_by, new_data, note)
        VALUES (?, ?, 'create', ?, ?, ?)
    ")->execute([
        'production_outputs',
        $outputId,
        $user['id'],
        json_encode([
            'output_no'             => $outputNo,
            'output_date'           => $outputDate,
            'production_receipt_id' => $receiptId,
            'quantity_completed'    => $qtyOK,
            'quantity_defect'       => $qtyNG,
        ], JSON_UNESCAPED_UNICODE),
        'Tao output ' . $outputNo
    ]);

    $pdo->commit();
    echo json_encode([
        'ok'  => true,
        'msg' => 'Da luu output ' . $outputNo,
        'id'  => $outputId,
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}