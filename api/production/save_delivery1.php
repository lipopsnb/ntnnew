<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
header('Content-Type: application/json');
requireLogin();
requireRole('director','accountant','warehouse','production','manager');

$pdo  = getDBConnection();
$user = currentUser();

if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok'=>false,'msg'=>'CSRF invalid']); exit;
}

$customerId   = (int)($_POST['customer_id']   ?? 0);
$deliveryDate = trim($_POST['delivery_date']  ?? date('Y-m-d'));
$status       = in_array($_POST['status'] ?? '', ['draft','confirmed']) ? $_POST['status'] : 'draft';
$note         = trim($_POST['note'] ?? '') ?: null;
$items        = $_POST['items'] ?? [];

// ── DEBUG: xem PHP nhận được gì ──────────────────────────────────────
// Bỏ comment dòng dưới nếu muốn xem raw POST
// error_log('POST items: ' . print_r($items, true));

if (!$customerId || empty($items)) {
    echo json_encode([
        'ok'    => false,
        'msg'   => 'Thiếu khách hàng hoặc sản phẩm',
        'debug' => ['customer_id'=>$customerId, 'items_count'=>count($items)]
    ]); exit;
}

$validItems = [];
$debugItems = [];

foreach ($items as $idx => $it) {
    $pcId             = (int)($it['product_code_id']     ?? 0);
    $productOutputId  = (int)($it['production_output_id'] ?? 0);
    $qty              = (float)($it['quantity']           ?? 0);
    $price            = (float)($it['unit_price']         ?? 0);
    $desc             = trim($it['description']           ?? '');
    $unit             = trim($it['unit']                  ?? '');

    // Log mỗi dòng để debug
    $debugItems[] = [
        'idx'                 => $idx,
        'product_code_id'     => $pcId,
        'production_output_id'=> $productOutputId,
        'quantity'            => $qty,
        'raw'                 => $it,
    ];

    if ($pcId && $productOutputId && $qty > 0) {
        $validItems[] = [
            'product_code_id'     => $pcId,
            'production_output_id'=> $productOutputId,
            'description'         => $desc,
            'unit'                => $unit,
            'quantity'            => $qty,
            'unit_price'          => $price,
            'total_price'         => round($qty * $price),
        ];
    }
}

if (empty($validItems)) {
    // ✅ Trả về debug info để xem vấn đề
    echo json_encode([
        'ok'    => false,
        'msg'   => 'Không có dòng hợp lệ',
        'debug' => $debugItems
    ]); exit;
}

try {
    $pdo->beginTransaction();

    $pdo->prepare("
        INSERT INTO document_sequences (doc_type, doc_date, last_seq) VALUES ('GH',?,1)
        ON DUPLICATE KEY UPDATE last_seq = last_seq + 1
    ")->execute([$deliveryDate]);
    $seq = $pdo->query("
        SELECT last_seq FROM document_sequences
        WHERE doc_type='GH' AND doc_date='$deliveryDate'
    ")->fetchColumn();
    $deliveryNo = 'GH-' . date('Ymd', strtotime($deliveryDate)) . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);

    $totalAmount = array_sum(array_column($validItems, 'total_price'));

    $pdo->prepare("
        INSERT INTO delivery_notes
            (delivery_no, delivery_date, customer_id, total_amount, status, note, created_by)
        VALUES (?,?,?,?,?,?,?)
    ")->execute([$deliveryNo, $deliveryDate, $customerId, $totalAmount, $status, $note, $user['id']]);

    $deliveryId = $pdo->lastInsertId();

    $stmtItem = $pdo->prepare("
        INSERT INTO delivery_note_items
            (delivery_note_id, production_output_id, product_code_id,
             description, unit, quantity, unit_price, total_price)
        VALUES (?,?,?,?,?,?,?,?)
    ");
    foreach ($validItems as $it) {
        $stmtItem->execute([
            $deliveryId,
            $it['production_output_id'],
            $it['product_code_id'],
            $it['description'],
            $it['unit'],
            $it['quantity'],
            $it['unit_price'],
            $it['total_price'],
        ]);
    }

    $pdo->commit();
    echo json_encode(['ok'=>true,'msg'=>'Đã tạo biên bản','delivery_no'=>$deliveryNo,'id'=>$deliveryId]);

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log($e->getMessage());
    echo json_encode(['ok'=>false,'msg'=>'Lỗi hệ thống: '.$e->getMessage()]);
}