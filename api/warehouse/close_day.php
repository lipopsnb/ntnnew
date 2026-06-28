<?php
/**
 * API: Chốt ngày - chuyển tồn SX về kho
 * POST (manual): close_date, csrf_token
 * CLI  (cron):   php close_day.php --cron --date=YYYY-MM-DD
 *
 * Logic:
 *  1. Lấy production_stock của ngày cần chốt (tất cả mã SP)
 *  2. Với mỗi mã SP:
 *     - qty_completed → warehouse_stock.qty_completed (HT trả về kho)
 *     - qty_defect    → warehouse_stock.qty_defect    (Lỗi trả về kho)
 *     - qty_pending   → warehouse_stock.qty_pending   (Chưa làm trả về kho)
 *  3. Reset production_stock về 0 cho ngày đó
 *  4. Ghi day_close_log + warehouse_stock_log
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';

$isCron = (php_sapi_name() === 'cli') && in_array('--cron', $argv ?? []);

if (!$isCron) {
    header('Content-Type: application/json');
    requireLogin();
    requireRole('director','manager','warehouse');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'msg' => 'Method not allowed']); exit;
    }
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid CSRF token']); exit;
    }
    $closeDate = trim($_POST['close_date'] ?? date('Y-m-d'));
    $userId    = currentUser()['id'];
    $closeType = 'manual';
} else {
    // Cron: lấy ngày từ argument hoặc hôm qua
    $dateArg   = null;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--date=')) {
            $dateArg = substr($arg, 7);
        }
    }
    $closeDate = $dateArg ?: date('Y-m-d', strtotime('yesterday'));
    $userId    = null;
    $closeType = 'auto';
}

$pdo = getDBConnection();

// Kiểm tra đã chốt ngày này chưa
$already = $pdo->prepare("SELECT id FROM day_close_log WHERE close_date = ?");
$already->execute([$closeDate]);
if ($already->fetch()) {
    $msg = "Ngày $closeDate đã được chốt rồi";
    if ($isCron) { echo $msg . PHP_EOL; exit(0); }
    echo json_encode(['ok' => false, 'msg' => $msg]); exit;
}

// Lấy tồn SX ngày cần chốt
$stocks = $pdo->prepare("
    SELECT product_code_id,
           qty_pending, qty_completed, qty_defect
    FROM production_stock
    WHERE stock_date = ?
      AND (qty_pending > 0 OR qty_completed > 0 OR qty_defect > 0)
");
$stocks->execute([$closeDate]);
$stocks = $stocks->fetchAll(PDO::FETCH_ASSOC);

if (empty($stocks)) {
    $msg = "Không có tồn SX ngày $closeDate để chốt";
    if ($isCron) { echo $msg . PHP_EOL; exit(0); }
    echo json_encode(['ok' => true, 'msg' => $msg, 'rows' => 0]); exit;
}

try {
    $pdo->beginTransaction();

    $totalCompleted = 0;
    $totalDefect    = 0;
    $totalPending   = 0;

    foreach ($stocks as $s) {
        $pcId      = $s['product_code_id'];
        $qtyComp   = (float)$s['qty_completed'];
        $qtyDef    = (float)$s['qty_defect'];
        $qtyPend   = (float)$s['qty_pending'];

        // 1. Cộng về warehouse_stock
        $pdo->prepare("
            INSERT INTO warehouse_stock (product_code_id, qty_pending, qty_completed, qty_defect)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                qty_pending   = qty_pending   + VALUES(qty_pending),
                qty_completed = qty_completed + VALUES(qty_completed),
                qty_defect    = qty_defect    + VALUES(qty_defect)
        ")->execute([$pcId, $qtyPend, $qtyComp, $qtyDef]);

        // 2. Reset production_stock
        $pdo->prepare("
            UPDATE production_stock
            SET qty_pending = 0, qty_completed = 0, qty_defect = 0,
                updated_at = NOW()
            WHERE product_code_id = ? AND stock_date = ?
        ")->execute([$pcId, $closeDate]);

        // 3. Ghi log từng loại
        $logItems = [
            ['return_completed', 'completed', $qtyComp],
            ['return_defect',    'defect',    $qtyDef],
            ['return_pending',   'pending',   $qtyPend],
        ];
        foreach ($logItems as [$txnType, $stockType, $qty]) {
            if ($qty <= 0) continue;
            $pdo->prepare("
                INSERT INTO warehouse_stock_log
                    (product_code_id, log_date, txn_type, stock_type,
                     qty_change, ref_table, ref_id, note, created_by)
                VALUES (?, ?, ?, ?, ?, 'day_close_log', NULL, ?, ?)
            ")->execute([
                $pcId, $closeDate, $txnType, $stockType, $qty,
                "Chốt ngày $closeDate", $userId
            ]);
        }

        $totalCompleted += $qtyComp;
        $totalDefect    += $qtyDef;
        $totalPending   += $qtyPend;
    }

    // 4. Ghi day_close_log
    $pdo->prepare("
        INSERT INTO day_close_log
            (close_date, close_type,
             qty_completed_returned, qty_defect_returned, qty_pending_returned,
             closed_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([
        $closeDate, $closeType,
        $totalCompleted, $totalDefect, $totalPending,
        $userId
    ]);

    $pdo->commit();

    $msg = "Chốt ngày $closeDate thành công. "
         . "HT: $totalCompleted | Lỗi: $totalDefect | Chưa làm: $totalPending";

    if ($isCron) { echo $msg . PHP_EOL; exit(0); }
    echo json_encode([
        'ok'                 => true,
        'msg'                => $msg,
        'qty_completed'      => $totalCompleted,
        'qty_defect'         => $totalDefect,
        'qty_pending'        => $totalPending,
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    $msg = 'Lỗi: ' . $e->getMessage();
    if ($isCron) { echo $msg . PHP_EOL; exit(1); }
    echo json_encode(['ok' => false, 'msg' => $msg]);
}