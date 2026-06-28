<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

$pdo = erp_db();
$id  = (int) ($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['ok' => false, 'msg' => 'Missing id']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        di.id,
        di.qty_delivered,
        di.unit_price,
        di.amount,
        pc.code AS product_code,
        pc.name AS product_name,
        pc.unit
    FROM delivery_items di
    INNER JOIN product_codes pc ON pc.id = di.product_code_id
    WHERE di.delivery_id = ?
    ORDER BY di.id ASC
");
$stmt->execute([$id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
