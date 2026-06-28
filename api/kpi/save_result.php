<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireLogin();
requireRole('director', 'accountant', 'manager', 'warehouse', 'production');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']); exit;
}
if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'CSRF không hợp lệ']); exit;
}

$pdo       = getDBConnection();
$user      = currentUser();
$items     = $_POST['items'] ?? [];
$processed = json_decode($_POST['processed'] ?? '{}', true);

if (empty($items)) {
    echo json_encode(['ok' => false, 'msg' => 'Không có dữ liệu']); exit;
}

// ── Hàm lấy tổng lương/ngày theo tổng tất cả khoản TRỪ chuyên cần ──
function getSalaryPerDay(PDO $pdo, int $userId): float
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(es.amount), 0) AS total
        FROM employee_salaries es
        JOIN salary_components sc ON es.component_id = sc.id
        WHERE es.user_id  = ?
          AND es.is_active = 1
          AND sc.component_code != 'attendance_bonus'
          AND sc.component_type IN ('earning', 'bonus')
    ");
    $stmt->execute([$userId]);
    $total = (float)$stmt->fetchColumn();
    return $total > 0 ? round($total / 26) : 0;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO kpi_results
            (kpi_assignment_id, actual_qty, salary_per_day,
             salary_actual, is_deducted, reason,
             confirmed_by, confirmed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            actual_qty     = VALUES(actual_qty),
            salary_per_day = VALUES(salary_per_day),
            salary_actual  = VALUES(salary_actual),
            is_deducted    = VALUES(is_deducted),
            reason         = VALUES(reason),
            confirmed_by   = VALUES(confirmed_by),
            confirmed_at   = NOW()
    ");

    // Cache salary_per_day theo user để không query lặp
    $salaryCache = [];

    foreach ($items as $assignId => $item) {
        $assignId  = (int)$assignId;
        $actualQty = (int)($item['actual_qty'] ?? 0);

        // ── Lấy user_id từ assignment ────────────────────────────────
        $aStmt = $pdo->prepare("SELECT user_id FROM kpi_assignments WHERE id = ?");
        $aStmt->execute([$assignId]);
        $assignUserId = (int)$aStmt->fetchColumn();

        // ── Tính salary_per_day theo TỔNG lương trừ chuyên cần ───────
        if (!isset($salaryCache[$assignUserId])) {
            $salaryCache[$assignUserId] = getSalaryPerDay($pdo, $assignUserId);
        }
        $salaryDay = $salaryCache[$assignUserId];

        // Chỉ dùng salary_per_day từ item nếu không tính được từ DB
        if ($salaryDay <= 0) {
            $salaryDay = (float)($item['salary_per_day'] ?? 0);
        }

        $extra = $processed[(string)$assignId] ?? null;

        if ($extra) {
            $isDeducted = (int)$extra['is_deducted'];
            $reason     = $extra['reason'] ?? '';

            if ($isDeducted && isset($item['kpi_target']) && (int)$item['kpi_target'] > 0) {
                // Không đạt + chọn trừ → tính theo tỷ lệ
                $salaryActual = round($salaryDay * $actualQty / (int)$item['kpi_target']);
            } else {
                // Vượt KPI hoặc không trừ → dùng giá trị frontend tính
                // (frontend đã tính: salary * qty / target cho vượt, hoặc giữ nguyên nếu không trừ)
                $salaryActual = (float)($extra['salary_actual'] ?? $salaryDay);
                // Không cap min ở đây — cho phép vượt KPI được thưởng hơn lương chuẩn
            }
        } else {
            $isDeducted   = 0;
            $reason       = '';
            $salaryActual = $salaryDay;
        }

        $stmt->execute([
            $assignId, $actualQty, $salaryDay,
            $salaryActual, $isDeducted, $reason,
            $user['id']
        ]);
    }

    $pdo->commit();
    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}