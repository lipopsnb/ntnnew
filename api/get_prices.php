<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';
requireLogin();

header('Content-Type: application/json');

try {
    $pdo = erp_db();
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) {
        echo json_encode([]);
        exit;
    }

    $sql = "SELECT p.product_code_id, pc.code, pc.name, pc.unit, p.unit_price
        FROM prices p
        INNER JOIN product_codes pc ON pc.id = p.product_code_id
        INNER JOIN (
            SELECT product_code_id, MAX(effective_date) AS latest_effective_date
            FROM prices
            WHERE customer_id = ?
            GROUP BY product_code_id
        ) latest_price ON latest_price.product_code_id = p.product_code_id AND latest_price.latest_effective_date = p.effective_date
        WHERE p.customer_id = ? AND pc.is_active = 1
        ORDER BY pc.code ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$customerId, $customerId]);
    $rows = array_map(static fn(array $row): array => [
        'product_code_id' => (int) $row['product_code_id'],
        'code' => $row['code'],
        'name' => $row['name'],
        'unit' => $row['unit'],
        'unit_price' => (float) $row['unit_price'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $throwable) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $throwable->getMessage()]);
}
