<?php
/**
 * API: Lấy danh sách sản phẩm của khách hàng từ các phiếu gia công.
 * GET ?customer_id=123
 * Trả về JSON: { ok: true, products: [ { job_order_id, job_code, item_id, product_code, product_name, unit, qty_ok, qty_delivered, qty_available, unit_price } ] }
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

$customerId = (int) ($_GET['customer_id'] ?? 0);
if ($customerId <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu customer_id']);
    exit;
}

$pdo = erp_db();

$stmt = $pdo->prepare("
    SELECT
        jo.id    AS job_order_id,
        jo.job_code,
        joi.id   AS item_id,
        pc.code  AS product_code,
        pc.name  AS product_name,
        pc.unit,
        joi.qty_ok,
        COALESCE(
            (SELECT SUM(di.qty_delivered)
             FROM delivery_items di
             WHERE di.job_order_item_id = joi.id), 0
        ) AS qty_delivered,
        joi.unit_price
    FROM job_order_items joi
    INNER JOIN job_orders jo  ON jo.id  = joi.job_order_id
    INNER JOIN product_codes pc ON pc.id = joi.product_code_id
    WHERE jo.customer_id = ?
      AND jo.status IN ('in_progress', 'done', 'delivered')
      AND joi.qty_ok > 0
    ORDER BY jo.received_date DESC, jo.id DESC, joi.id ASC
");
$stmt->execute([$customerId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$products = [];
foreach ($rows as $row) {
    $available = (float) $row['qty_ok'] - (float) $row['qty_delivered'];
    if ($available > 0) {
        $row['qty_available'] = round($available, 2);
        $products[] = $row;
    }
}

echo json_encode(['ok' => true, 'products' => $products], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
