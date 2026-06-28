<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireLogin();

$user = currentUser();
$pdo  = getDBConnection();

// ── Xử lý form chấm công thủ công ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $today  = date('Y-m-d');
    $now    = date('Y-m-d H:i:s');

    if ($action === 'check_in') {
        // INSERT IGNORE – bỏ qua nếu đã có bản ghi hôm nay
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO attendance_logs (user_id, work_date, check_in, status)
             VALUES (?, ?, ?, 'present')"
        );
        $stmt->execute([$user['id'], $today, $now]);
        setFlash('success', 'Chấm công vào ca thành công lúc ' . date('H:i'));

    } elseif ($action === 'check_out') {
        $stmt = $pdo->prepare(
            "UPDATE attendance_logs
             SET check_out = ?
             WHERE user_id = ? AND work_date = ? AND check_out IS NULL"
        );
        $stmt->execute([$now, $user['id'], $today]);
        setFlash('success', 'Chấm công ra ca thành công lúc ' . date('H:i'));
    }

    header('Location: /ntn_erp/modules/attendance/index.php');
    exit();
}

// ── Tháng / năm đang xem ────────────────────────────────────────────────────
$viewMonth = (int)($_GET['month'] ?? date('m'));
$viewYear  = (int)($_GET['year']  ?? date('Y'));
if ($viewMonth < 1)  { $viewMonth = 12; $viewYear--; }
if ($viewMonth > 12) { $viewMonth = 1;  $viewYear++; }

// ── Chấm công hôm nay ───────────────────────────────────────────────────────
$today = date('Y-m-d');
$stmt  = $pdo->prepare("SELECT * FROM attendance_logs WHERE user_id = ? AND work_date = ?");
$stmt->execute([$user['id'], $today]);
$todayLog = $stmt->fetch();

// ── Chấm công cả tháng ──────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT * FROM attendance_logs
     WHERE user_id = ? AND MONTH(work_date) = ? AND YEAR(work_date) = ?
     ORDER BY work_date"
);
$stmt->execute([$user['id'], $viewMonth, $viewYear]);
$monthLogs = [];
foreach ($stmt->fetchAll() as $log) {
    $monthLogs[$log['work_date']] = $log;
}

// ── Nghỉ phép đã duyệt trong tháng ─────────────────────────────────────────
// Dùng đúng tên cột: date_from / date_to (theo database/ntn_erp.sql)
$stmt = $pdo->prepare(
    "SELECT lr.*, lt.name AS leave_type_name
     FROM leave_requests lr
     JOIN leave_types lt ON lt.id = lr.leave_type_id
     WHERE lr.user_id = ?
       AND lr.status = 'approved'
       AND (
           (MONTH(lr.date_from) = ? AND YEAR(lr.date_from) = ?)
           OR (MONTH(lr.date_to)   = ? AND YEAR(lr.date_to)   = ?)
           OR (lr.date_from <= ? AND lr.date_to >= ?)
       )"
);
$monthStart = sprintf('%04d-%02d-01', $viewYear, $viewMonth);
$monthEnd   = sprintf('%04d-%02d-%02d', $viewYear, $viewMonth,
                      cal_days_in_month(CAL_GREGORIAN, $viewMonth, $viewYear));
$stmt->execute([
    $user['id'],
    $viewMonth, $viewYear,
    $viewMonth, $viewYear,
    $monthEnd, $monthStart,
]);
$approvedLeaves = $stmt->fetchAll();

// ── Map ngày nghỉ phép ──────────────────────────────────────────────────────
$leaveDays = [];
foreach ($approvedLeaves as $leave) {
    $start = strtotime($leave['date_from']);
    $end   = strtotime($leave['date_to']);
    for ($d = $start; $d <= $end; $d += 86400) {
        $leaveDays[date('Y-m-d', $d)] = $leave['leave_type_name'];
    }
}

// ── Thống kê tháng ──────────────────────────────────────────────────────────
$totalWorkDays  = 0;
$totalWorkHours = 0;
$lateDays       = 0;
foreach ($monthLogs as $log) {
    if (!empty($log['check_in'])) {
        $totalWorkDays++;
        // Tính giờ từ check_in / check_out nếu có (tránh phụ thuộc cột work_hours)
        if (!empty($log['check_out'])) {
            $diff = (strtotime($log['check_out']) - strtotime($log['check_in'])) / 3600;
            $totalWorkHours += round($diff, 2);
        }
        if (date('H:i', strtotime($log['check_in'])) > '08:15') {
            $lateDays++;
        }
    }
}

$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">⏰ Chấm công</h4>
            <p class="text-muted mb-0">
                <?= htmlspecialchars($user['full_name']) ?> &bull; <?= date('l, d/m/Y') ?>
            </p>
        </div>
        <?php if (hasRole('director','manager','accountant','production')): ?>
        <a href="/ntn_erp/modules/attendance/all_attendance.php"
           class="btn btn-outline-primary btn-sm">
            <i class="fas fa-table me-1"></i> Xem tất cả nhân viên
        </a>
        <?php endif; ?>
    </div>

    <?php showFlash(); ?>

    <div class="row g-3">

        <!-- ── Cột trái: Chấm công hôm nay + Thống kê ── -->
        <div class="col-lg-4">

            <!-- Chấm công hôm nay -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0">📅 Hôm nay – <?= date('d/m/Y') ?></h6>
                </div>
                <div class="card-body text-center py-4">
                    <?php
                    $canCheckIn  = !$todayLog || empty($todayLog['check_in']);
                    $canCheckOut = $todayLog && !empty($todayLog['check_in']) && empty($todayLog['check_out']);
                    ?>
                    <div class="mb-3">
                        <div class="row">
                            <div class="col-6 border-end">
                                <div class="text-muted small mb-1">Giờ vào</div>
                                <div class="fs-4 fw-bold <?= !empty($todayLog['check_in']) ? 'text-success' : 'text-muted' ?>">
                                    <?= !empty($todayLog['check_in'])
                                        ? date('H:i', strtotime($todayLog['check_in']))
                                        : '--:--' ?>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted small mb-1">Giờ ra</div>
                                <div class="fs-4 fw-bold <?= !empty($todayLog['check_out']) ? 'text-danger' : 'text-muted' ?>">
                                    <?= !empty($todayLog['check_out'])
                                        ? date('H:i', strtotime($todayLog['check_out']))
                                        : '--:--' ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($todayLog && !empty($todayLog['check_out'])): ?>
                        <?php
                        $workedH = round(
                            (strtotime($todayLog['check_out']) - strtotime($todayLog['check_in'])) / 3600,
                            2
                        );
                        ?>
                        <div class="alert alert-success py-2">
                            ✅ Đã hoàn thành ca hôm nay<br>
                            <strong><?= $workedH ?> giờ</strong>
                        </div>

                    <?php else: ?>
                        <div class="alert alert-warning py-2 small mb-3">
                            <i class="fas fa-info-circle me-1"></i>
                            <strong>Chú ý:</strong> Đang dùng chấm công thủ công.<br>
                            Khi lắp máy chấm công sẽ tự động.
                        </div>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <?php if ($canCheckIn): ?>
                                <input type="hidden" name="action" value="check_in">
                                <button type="submit" class="btn btn-success btn-lg w-100 mb-2"
                                        onclick="return confirm('Xác nhận chấm công VÀO?')">
                                    <i class="fas fa-sign-in-alt me-2"></i>Chấm công VÀO
                                </button>
                            <?php elseif ($canCheckOut): ?>
                                <input type="hidden" name="action" value="check_out">
                                <div class="alert alert-info py-2 small mb-2">
                                    Đã vào: <?= date('H:i', strtotime($todayLog['check_in'])) ?>
                                </div>
                                <button type="submit" class="btn btn-danger btn-lg w-100"
                                        onclick="return confirm('Xác nhận chấm công RA?')">
                                    <i class="fas fa-sign-out-alt me-2"></i>Chấm công RA
                                </button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Thống kê tháng -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h6 class="mb-0 fw-bold">📊 Tháng <?= $viewMonth . '/' . $viewYear ?></h6>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between">
                            <span><i class="fas fa-check-circle text-success me-2"></i>Ngày công</span>
                            <strong><?= $totalWorkDays ?> ngày</strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><i class="fas fa-clock text-primary me-2"></i>Tổng giờ làm</span>
                            <strong><?= number_format($totalWorkHours, 1) ?> giờ</strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><i class="fas fa-exclamation-circle text-warning me-2"></i>Đi trễ</span>
                            <strong class="text-warning"><?= $lateDays ?> lần</strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><i class="fas fa-umbrella-beach text-info me-2"></i>Ngày nghỉ phép</span>
                            <strong class="text-info"><?= count($leaveDays) ?> ngày</strong>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- ── Cột phải: Lịch tháng ── -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <a href="?month=<?= $viewMonth - 1 ?>&year=<?= $viewYear ?>"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <h6 class="mb-0 fw-bold">📅 Tháng <?= $viewMonth . '/' . $viewYear ?></h6>
                    <a href="?month=<?= $viewMonth + 1 ?>&year=<?= $viewYear ?>"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
                <div class="card-body p-2">
                    <!-- Chú thích -->
                    <div class="d-flex flex-wrap gap-2 mb-3 px-2">
                        <span class="badge-legend bg-success text-white">✅ Đúng giờ</span>
                        <span class="badge-legend bg-warning text-dark">⚠️ Đi trễ</span>
                        <span class="badge-legend bg-info text-white">🏖️ Nghỉ phép</span>
                        <span class="badge-legend bg-danger text-white">❌ Vắng</span>
                        <span class="badge-legend bg-light text-muted">– Nghỉ CN</span>
                    </div>

                    <table class="table table-bordered calendar-table mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Thứ 2</th><th>Thứ 3</th><th>Thứ 4</th>
                                <th>Thứ 5</th><th>Thứ 6</th><th>Thứ 7</th>
                                <th class="text-danger">CN</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $firstDay   = mktime(0, 0, 0, $viewMonth, 1, $viewYear);
                        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $viewMonth, $viewYear);
                        $startDow   = (int)date('N', $firstDay); // 1=Mon … 7=Sun

                        echo '<tr>';
                        // Ô trống trước ngày 1
                        for ($i = 1; $i < $startDow; $i++) {
                            echo '<td class="bg-light"></td>';
                        }

                        $col = $startDow;
                        for ($day = 1; $day <= $daysInMonth; $day++) {
                            $dateStr  = sprintf('%04d-%02d-%02d', $viewYear, $viewMonth, $day);
                            $dow      = (int)date('N', mktime(0, 0, 0, $viewMonth, $day, $viewYear));
                            $isToday  = ($dateStr === date('Y-m-d'));
                            $isSunday = ($dow === 7);
                            $log      = $monthLogs[$dateStr] ?? null;
                            $isLeave  = isset($leaveDays[$dateStr]);
                            $isFuture = ($dateStr > date('Y-m-d'));

                            $cellClass = $isToday ? ' today-cell border border-primary border-2' : '';
                            $dayNumClass = $isToday ? 'fw-bold text-primary' : '';
                            $content = '';

                            if ($isSunday) {
                                $cellClass .= ' bg-light text-muted';
                                $content = '<small class="text-muted">CN</small>';
                            } elseif ($isFuture) {
                                $content = '';
                            } elseif ($isLeave && !$log) {
                                $cellClass .= ' leave-cell';
                                $content = '<div class="small text-info fw-bold">🏖️ Phép</div>';
                            } elseif ($log && !empty($log['check_in'])) {
                                $isLate    = date('H:i', strtotime($log['check_in'])) > '08:15';
                                $cellClass .= $isLate ? ' late-cell' : ' present-cell';
                                $outBadge  = !empty($log['check_out'])
                                    ? date('H:i', strtotime($log['check_out']))
                                    : '?';
                                $content = '<div class="att-time">
                                    <span class="badge bg-success badge-sm">▶ '
                                        . date('H:i', strtotime($log['check_in'])) . '</span><br>
                                    <span class="badge bg-danger badge-sm mt-1">◼ ' . $outBadge . '</span>
                                </div>';
                            } else {
                                $cellClass .= ' absent-cell';
                                $content = '<div class="small text-danger">❌</div>';
                            }

                            echo "<td class='calendar-day $cellClass'>
                                    <div class='day-number $dayNumClass'>$day</div>
                                    $content
                                  </td>";

                            if ($col % 7 === 0 && $day < $daysInMonth) echo '</tr><tr>';
                            $col++;
                        }
                        // Ô trống cuối tháng
                        while ($col % 7 !== 1) {
                            echo '<td class="bg-light"></td>';
                            $col++;
                        }
                        echo '</tr>';
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div><!-- /.col-lg-8 -->

    </div><!-- /.row -->
</div>
</div>

<style>
.calendar-table td    { height: 65px; vertical-align: top; padding: 4px; }
.day-number           { font-size: 12px; font-weight: 600; margin-bottom: 2px; }
.present-cell         { background: #f0fff4; }
.late-cell            { background: #fffbf0; }
.leave-cell           { background: #e8f4fd; }
.absent-cell          { background: #fff5f5; }
.today-cell           { outline: 2px solid #0d6efd !important; }
.att-time .badge-sm   { font-size: 10px; padding: 2px 5px; }
.badge-legend         { font-size: 11px; padding: 3px 8px; border-radius: 20px; }
</style>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
