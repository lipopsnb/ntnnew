<?php
/**
 * API: Lấy tóm tắt giao hàng theo phiếu gia công.
 * GET ?job_order_id=123
 * Trả về JSON: {
 *   ok: true,
 *   job_order: { id, job_code, customer_name, status },
 *   items: [ { item_id, product_code, product_name, unit, qty_ok, qty_delivered, qty_remaining } ],
 *   deliveries: [ { delivery_id, delivery_code, delivery_date, total_qty } ]
 * }
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

$jobOrderId = (int) ($_GET['job_order_id'] ?? 0);
if ($jobOrderId <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu job_order_id']);
    exit;
}

$pdo = erp_db();

// Thông tin phiếu gia công
$joStmt = $pdo->prepare("
    SELECT jo.id, jo.job_code, jo.status, c.name AS customer_name
    FROM job_orders jo
    INNER JOIN customers c ON c.id = jo.customer_id
    WHERE jo.id = ?
");
$joStmt->execute([$jobOrderId]);
$jobOrder = $joStmt->fetch(PDO::FETCH_ASSOC);
if (!$jobOrder) {
    echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy phiếu gia công']);
    exit;
}

// Chi tiết sản phẩm với tình trạng giao hàng
$itemStmt = $pdo->prepare("
    SELECT
        joi.id AS item_id,
        pc.code AS product_code,
        pc.name AS product_name,
        pc.unit,
        joi.qty_received,
        joi.qty_ok,
        joi.qty_ng,
        COALESCE(
            (SELECT SUM(di.qty_delivered)
             FROM delivery_items di
             WHERE di.job_order_item_id = joi.id), 0
        ) AS qty_delivered
    FROM job_order_items joi
    INNER JOIN product_codes pc ON pc.id = joi.product_code_id
    WHERE joi.job_order_id = ?
    ORDER BY joi.id ASC
");
$itemStmt->execute([$jobOrderId]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($items as &$item) {
    $item['qty_remaining'] = round(max(0, (float) $item['qty_ok'] - (float) $item['qty_delivered']), 2);
}
unset($item);

// Danh sách phiếu giao hàng
$dlStmt = $pdo->prepare("
    SELECT
        d.id AS delivery_id,
        d.delivery_code,
        d.delivery_date,
        d.recipient_name,
        COALESCE(SUM(di.qty_delivered), 0) AS total_qty,
        COALESCE(SUM(di.amount), 0) AS total_amount
    FROM deliveries d
    LEFT JOIN delivery_items di ON di.delivery_id = d.id
    WHERE d.job_order_id = ?
    GROUP BY d.id
    ORDER BY d.delivery_date ASC, d.id ASC
");
$dlStmt->execute([$jobOrderId]);
$deliveries = $dlStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'ok'         => true,
    'job_order'  => $jobOrder,
    'items'      => $items,
    'deliveries' => $deliveries,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
