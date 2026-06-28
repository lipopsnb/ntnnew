<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireRole('director', 'accountant', 'manager', 'production');

$pdo = getDBConnection();
$filterMonth = max(1, min(12, (int) ($_GET['month'] ?? date('n'))));
$filterYear = max(2020, min(2100, (int) ($_GET['year'] ?? date('Y'))));
$filterUser = (int) ($_GET['employee_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$monthStart = sprintf('%04d-%02d-01', $filterYear, $filterMonth);
$monthEnd = sprintf('%04d-%02d-%02d', $filterYear, $filterMonth, cal_days_in_month(CAL_GREGORIAN, $filterMonth, $filterYear));

function attendanceHolidaySet(PDO $pdo, string $startDate, string $endDate): array {
    $stmt = $pdo->prepare("SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?");
    $stmt->execute([$startDate, $endDate]);
    return array_flip(array_column($stmt->fetchAll(), 'holiday_date'));
}

function attendanceLeaveCounts(PDO $pdo, array $userIds, string $startDate, string $endDate, array $holidaySet): array {
    if (!$userIds) return [];
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $sql = "SELECT user_id, start_date, end_date
            FROM leave_requests
            WHERE status = 'approved'
              AND user_id IN ($placeholders)
              AND start_date <= ?
              AND end_date >= ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($userIds, [$endDate, $startDate]));
    $counts = array_fill_keys($userIds, 0);
    foreach ($stmt->fetchAll() as $row) {
        $cursor = new DateTime($row['start_date'] > $startDate ? $row['start_date'] : $startDate);
        $limit = new DateTime($row['end_date'] < $endDate ? $row['end_date'] : $endDate);
        while ($cursor <= $limit) {
            $date = $cursor->format('Y-m-d');
            $dow = (int) $cursor->format('N');
            if ($dow < 6 && !isset($holidaySet[$date])) {
                $counts[(int) $row['user_id']]++;
            }
            $cursor->modify('+1 day');
        }
    }
    return $counts;
}

function attendanceSummaryRows(PDO $pdo, array $users, string $startDate, string $endDate, array $holidaySet): array {
    if (!$users) return [];
    $userIds = array_map(fn($row) => (int) $row['id'], $users);
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $sql = "SELECT u.id,
                   COUNT(CASE WHEN al.check_in IS NOT NULL THEN 1 END) AS days_present,
                   SUM(CASE WHEN al.is_late = 1 THEN 1 ELSE 0 END) AS late_count,
                   SUM(CASE WHEN al.early_leave = 1 THEN 1 ELSE 0 END) AS early_leave_count
            FROM users u
            LEFT JOIN attendance_logs al ON al.user_id = u.id AND al.work_date BETWEEN ? AND ?
            LEFT JOIN work_shifts ws ON ws.id = al.shift_id
            WHERE u.id IN ($placeholders)
            GROUP BY u.id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$startDate, $endDate], $userIds));
    $summary = [];
    foreach ($stmt->fetchAll() as $row) {
        $summary[(int) $row['id']] = $row;
    }
    $leaveCounts = attendanceLeaveCounts($pdo, $userIds, $startDate, $endDate, $holidaySet);
    $rows = [];
    foreach ($users as $user) {
        $sum = $summary[(int) $user['id']] ?? ['days_present' => 0, 'late_count' => 0, 'early_leave_count' => 0];
        $rows[] = [
            'id' => (int) $user['id'],
            'employee_code' => $user['employee_code'],
            'full_name' => $user['full_name'],
            'days_present' => (int) ($sum['days_present'] ?? 0),
            'late_count' => (int) ($sum['late_count'] ?? 0),
            'early_leave_count' => (int) ($sum['early_leave_count'] ?? 0),
            'leave_days' => (int) ($leaveCounts[(int) $user['id']] ?? 0),
        ];
    }
    return $rows;
}

$userFilterSql = ' FROM users u WHERE u.is_active = 1';
$userFilterParams = [];
if ($filterUser > 0) {
    $userFilterSql .= ' AND u.id = ?';
    $userFilterParams[] = $filterUser;
}

$countStmt = $pdo->prepare('SELECT COUNT(*)' . $userFilterSql);
$countStmt->execute($userFilterParams);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare('SELECT u.id, u.employee_code, u.full_name' . $userFilterSql . ' ORDER BY u.full_name ASC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset);
$listStmt->execute($userFilterParams);
$pageUsers = $listStmt->fetchAll();
$holidaySet = attendanceHolidaySet($pdo, $monthStart, $monthEnd);
$rows = attendanceSummaryRows($pdo, $pageUsers, $monthStart, $monthEnd, $holidaySet);

$employeeOptions = $pdo->query("SELECT id, employee_code, full_name FROM users WHERE is_active = 1 ORDER BY full_name ASC")->fetchAll();

if (($_GET['export'] ?? '') === '1') {
    $exportStmt = $pdo->prepare('SELECT u.id, u.employee_code, u.full_name' . $userFilterSql . ' ORDER BY u.full_name ASC');
    $exportStmt->execute($userFilterParams);
    $exportRows = attendanceSummaryRows($pdo, $exportStmt->fetchAll(), $monthStart, $monthEnd, $holidaySet);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance-' . $filterYear . '-' . str_pad((string) $filterMonth, 2, '0', STR_PAD_LEFT) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Mã NV', 'Nhân viên', 'Ngày có mặt', 'Đi trễ', 'Về sớm', 'Ngày nghỉ']);
    foreach ($exportRows as $row) {
        fputcsv($out, [$row['employee_code'], $row['full_name'], $row['days_present'], $row['late_count'], $row['early_leave_count'], $row['leave_days']]);
    }
    fclose($out);
    exit();
}

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="main-content">
    <div class="container-fluid py-4">
        <?php showFlash(); ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h4 class="mb-1">Bảng chấm công nhân viên</h4>
                <p class="text-muted mb-0">Tháng <?= $filterMonth ?>/<?= $filterYear ?></p>
            </div>
            <a class="btn btn-success btn-sm" href="?<?= e(http_build_query(['month' => $filterMonth, 'year' => $filterYear, 'employee_id' => $filterUser, 'export' => 1])) ?>">Xuất dữ liệu</a>
        </div>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Tháng</label>
                        <input type="number" class="form-control" name="month" min="1" max="12" value="<?= $filterMonth ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Năm</label>
                        <input type="number" class="form-control" name="year" min="2020" max="2100" value="<?= $filterYear ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Nhân viên</label>
                        <select class="form-select" name="employee_id">
                            <option value="0">Tất cả nhân viên</option>
                            <?php foreach ($employeeOptions as $option): ?>
                                <option value="<?= (int) $option['id'] ?>" <?= $filterUser === (int) $option['id'] ? 'selected' : '' ?>><?= e($option['employee_code'] . ' - ' . $option['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-grid">
                        <button class="btn btn-primary" type="submit">Lọc dữ liệu</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Mã NV</th>
                            <th>Nhân viên</th>
                            <th class="text-center">Ngày có mặt</th>
                            <th class="text-center">Đi trễ</th>
                            <th class="text-center">Về sớm</th>
                            <th class="text-center">Ngày nghỉ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">Không có dữ liệu.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= e($row['employee_code']) ?></td>
                                <td><?= e($row['full_name']) ?></td>
                                <td class="text-center fw-semibold text-success"><?= $row['days_present'] ?></td>
                                <td class="text-center text-warning fw-semibold"><?= $row['late_count'] ?></td>
                                <td class="text-center text-info fw-semibold"><?= $row['early_leave_count'] ?></td>
                                <td class="text-center text-primary fw-semibold"><?= $row['leave_days'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white">
                    <nav>
                        <ul class="pagination pagination-sm mb-0 justify-content-end">
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= e(http_build_query(['month' => $filterMonth, 'year' => $filterYear, 'employee_id' => $filterUser, 'page' => $p])) ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
