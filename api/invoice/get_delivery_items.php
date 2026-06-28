<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
header('Content-Type: application/json');
requireLogin();

$pdo = getDBConnection();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Missing id']); exit; }

$items = $pdo->prepare("
    SELECT di.product_code_id, di.description, di.unit,
           di.quantity, di.unit_price, di.total_price,
           pc.product_code
    FROM delivery_items di
    JOIN product_codes pc ON di.product_code_id = pc.id
    WHERE di.delivery_id = ?
    ORDER BY di.id
");
$items->execute([$id]);
echo json_encode(['ok'=>true,'items'=>$items->fetchAll(PDO::FETCH_ASSOC)]);