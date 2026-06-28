<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
requireLogin();

header('Content-Type: application/json');

$pdo   = getDBConnection();
$today = date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM kpi_assignments ka
    LEFT JOIN kpi_results kr ON kr.kpi_assignment_id = ka.id
    WHERE ka.assign_date = ?
    AND kr.id IS NULL
");
$stmt->execute([$today]);
echo json_encode(['count' => (int)$stmt->fetchColumn()]);