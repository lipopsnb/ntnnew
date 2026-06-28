<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireLogin();

$pdo = getDBConnection();
$user = currentUser();

function attendanceMonthRange(int $year, int $month): array {
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));
    return [$start, $end];
}

function getShiftForDate(PDO $pdo, int $userId, string $workDate): ?array {
    $scheduleStmt = $pdo->prepare(
        "SELECT ws.*
         FROM shift_schedules ss
         INNER JOIN work_shifts ws ON ws.id = ss.shift_id
         WHERE ss.user_id = ? AND ss.work_date = ?
         LIMIT 1"
    );
    $scheduleStmt->execute([$userId, $workDate]);
    $shift = $scheduleStmt->fetch();
    if ($shift) {
        return $shift;
    }

    $assignStmt = $pdo->prepare(
        "SELECT ws.*
         FROM employee_shifts es
         INNER JOIN work_shifts ws ON ws.id = es.shift_id
         WHERE es.user_id = ?
           AND es.effective_date <= ?
           AND (es.end_date IS NULL OR es.end_date = '' OR es.end_date >= ?)
         ORDER BY es.effective_date DESC, es.id DESC
         LIMIT 1"
    );
    $assignStmt->execute([$userId, $workDate, $workDate]);
    return $assignStmt->fetch() ?: null;
}

function getHolidayMapForRange(PDO $pdo, string $startDate, string $endDate): array {
    $stmt = $pdo->prepare("SELECT holiday_date, holiday_name FROM holidays WHERE holiday_date BETWEEN ? AND ?");
    $stmt->execute([$startDate, $endDate]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[$row['holiday_date']] = $row['holiday_name'];
    }
    return $map;
}

function getApprovedLeaveMapForRange(PDO $pdo, int $userId, string $startDate, string $endDate): array {
    $stmt = $pdo->prepare(
        "SELECT leave_type, start_date, end_date
         FROM leave_requests
         WHERE user_id = ?
           AND status = 'approved'
           AND start_date <= ?
           AND end_date >= ?"
    );
    $stmt->execute([$userId, $endDate, $startDate]);
    $map = [];
    foreach ($stmt->fetchAll() as $leave) {
        $cursor = new DateTime($leave['start_date'] > $startDate ? $leave['start_date'] : $startDate);
        $limit = new DateTime($leave['end_date'] < $endDate ? $leave['end_date'] : $endDate);
        while ($cursor <= $limit) {
            $map[$cursor->format('Y-m-d')] = $leave['leave_type'];
            $cursor->modify('+1 day');
        }
    }
    return $map;
}

function getStatusMeta(string $status): array {
    $map = [
        'present' => ['text' => 'Có mặt', 'class' => 'success'],
        'late' => ['text' => 'Đi trễ', 'class' => 'warning text-dark'],
        'early_leave' => ['text' => 'Về sớm', 'class' => 'info text-dark'],
        'leave' => ['text' => 'Nghỉ phép', 'class' => 'primary'],
        'holiday' => ['text' => 'Ngày lễ', 'class' => 'danger'],
        'weekend' => ['text' => 'Cuối tuần', 'class' => 'secondary'],
        'absent' => ['text' => 'Vắng', 'class' => 'dark'],
        'future' => ['text' => 'Chưa tới ngày', 'class' => 'light text-dark'],
    ];
    return $map[$status] ?? $map['future'];
}

function detectDayStatus(string $date, ?array $log, array $leaveMap, array $holidayMap): string {
    if (isset($holidayMap[$date])) return 'holiday';
    if (isset($leaveMap[$date])) return 'leave';
    if ($log) {
        if (!empty($log['early_leave'])) return 'early_leave';
        if (!empty($log['is_late'])) return 'late';
        return 'present';
    }
    if (date('N', strtotime($date)) >= 6) return 'weekend';
    if ($date > date('Y-m-d')) return 'future';
    return 'absent';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc không hợp lệ.');
        header('Location: /ntn_erp/modules/attendance/index.php');
        exit();
    }

    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');
    $action = $_POST['action'] ?? '';

    $todayStmt = $pdo->prepare("SELECT * FROM attendance_logs WHERE user_id = ? AND work_date = ? LIMIT 1");
    $todayStmt->execute([(int) $user['id'], $today]);
    $todayLog = $todayStmt->fetch() ?: null;
    $shift = getShiftForDate($pdo, (int) $user['id'], $today);

    if ($action === 'check_in') {
        if ($todayLog && !empty($todayLog['check_in'])) {
            setFlash('danger', 'Bạn đã check-in hôm nay rồi.');
        } else {
            $isLate = 0;
            $lateMinutes = 0;
            if ($shift && !empty($shift['start_time'])) {
                $startTs = strtotime($today . ' ' . $shift['start_time']);
                $lateTs = $startTs + ((int) $shift['late_threshold'] * 60);
                if (strtotime($now) > $lateTs) {
                    $isLate = 1;
                    $lateMinutes = (int) floor((strtotime($now) - $startTs) / 60);
                }
            }

            if ($todayLog) {
                $stmt = $pdo->prepare(
                    "UPDATE attendance_logs
                     SET check_in = ?, shift_id = ?, source = 'manual', updated_at = NOW(), is_late = ?, late_minutes = ?
                     WHERE id = ?"
                );
                $stmt->execute([$now, $shift['id'] ?? null, $isLate, $lateMinutes, $todayLog['id']]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO attendance_logs
                        (user_id, check_in, work_date, shift_id, work_hours, source, note, created_at, updated_at, is_late, late_minutes, early_leave, early_leave_minutes)
                     VALUES (?, ?, ?, ?, 0, 'manual', ?, NOW(), NOW(), ?, ?, 0, 0)"
                );
                $stmt->execute([(int) $user['id'], $now, $today, $shift['id'] ?? null, 'Chấm công thủ công', $isLate, $lateMinutes]);
            }
            setFlash('success', 'Check-in thành công lúc ' . date('H:i', strtotime($now)) . '.');
        }
    }

    if ($action === 'check_out') {
        if (!$todayLog || empty($todayLog['check_in'])) {
            setFlash('danger', 'Bạn chưa check-in hôm nay.');
        } elseif (!empty($todayLog['check_out'])) {
            setFlash('danger', 'Bạn đã check-out hôm nay rồi.');
        } else {
            $earlyLeave = 0;
            $earlyMinutes = 0;
            if ($shift && !empty($shift['end_time'])) {
                $endTs = strtotime($today . ' ' . $shift['end_time']);
                if (strtotime($now) < $endTs) {
                    $earlyLeave = 1;
                    $earlyMinutes = (int) floor(($endTs - strtotime($now)) / 60);
                }
            }
            $workHours = calcWorkHours($todayLog['check_in'], $now);
            $stmt = $pdo->prepare(
                "UPDATE attendance_logs
                 SET check_out = ?, work_hours = ?, source = 'manual', updated_at = NOW(), early_leave = ?, early_leave_minutes = ?
                 WHERE id = ?"
            );
            $stmt->execute([$now, $workHours, $earlyLeave, $earlyMinutes, $todayLog['id']]);
            setFlash('success', 'Check-out thành công lúc ' . date('H:i', strtotime($now)) . '.');
        }
    }

    header('Location: /ntn_erp/modules/attendance/index.php');
    exit();
}

$viewMonth = max(1, min(12, (int) ($_GET['month'] ?? date('n'))));
$viewYear = max(2020, min(2100, (int) ($_GET['year'] ?? date('Y'))));
[$monthStart, $monthEnd] = attendanceMonthRange($viewYear, $viewMonth);

$todayStmt = $pdo->prepare("SELECT al.*, ws.shift_name FROM attendance_logs al LEFT JOIN work_shifts ws ON ws.id = al.shift_id WHERE al.user_id = ? AND al.work_date = ? LIMIT 1");
$todayStmt->execute([(int) $user['id'], date('Y-m-d')]);
$todayLog = $todayStmt->fetch() ?: null;

$monthStmt = $pdo->prepare(
    "SELECT al.*, ws.shift_name
     FROM attendance_logs al
     LEFT JOIN work_shifts ws ON ws.id = al.shift_id
     WHERE al.user_id = ? AND al.work_date BETWEEN ? AND ?
     ORDER BY al.work_date ASC"
);
$monthStmt->execute([(int) $user['id'], $monthStart, $monthEnd]);
$monthLogs = [];
foreach ($monthStmt->fetchAll() as $log) {
    $monthLogs[$log['work_date']] = $log;
}

$holidayMap = getHolidayMapForRange($pdo, $monthStart, $monthEnd);
$leaveMap = getApprovedLeaveMapForRange($pdo, (int) $user['id'], $monthStart, $monthEnd);
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $viewMonth, $viewYear);
$presentCount = $lateCount = $earlyLeaveCount = $absentCount = 0;

for ($day = 1; $day <= $daysInMonth; $day++) {
    $date = sprintf('%04d-%02d-%02d', $viewYear, $viewMonth, $day);
    $status = detectDayStatus($date, $monthLogs[$date] ?? null, $leaveMap, $holidayMap);
    if ($status === 'present') $presentCount++;
    if ($status === 'late') $lateCount++;
    if ($status === 'early_leave') $earlyLeaveCount++;
    if ($status === 'absent') $absentCount++;
}

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="main-content">
    <div class="container-fluid py-4">
        <?php showFlash(); ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h4 class="mb-1">Chấm công cá nhân</h4>
                <p class="text-muted mb-0"><?= e($user['full_name']) ?> • Hôm nay <?= date('d/m/Y') ?></p>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="?month=<?= $viewMonth === 1 ? 12 : $viewMonth - 1 ?>&year=<?= $viewMonth === 1 ? $viewYear - 1 : $viewYear ?>">&laquo; Tháng trước</a>
                <a class="btn btn-outline-primary btn-sm" href="?month=<?= date('n') ?>&year=<?= date('Y') ?>">Tháng này</a>
                <a class="btn btn-outline-secondary btn-sm" href="?month=<?= $viewMonth === 12 ? 1 : $viewMonth + 1 ?>&year=<?= $viewMonth === 12 ? $viewYear + 1 : $viewYear ?>">Tháng sau &raquo;</a>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-primary text-white">Chấm công hôm nay</div>
                    <div class="card-body">
                        <div class="row text-center mb-3">
                            <div class="col-6 border-end">
                                <div class="text-muted small">Check-in</div>
                                <div class="fs-5 fw-bold"><?= $todayLog && $todayLog['check_in'] ? e(date('H:i', strtotime($todayLog['check_in']))) : '--:--' ?></div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted small">Check-out</div>
                                <div class="fs-5 fw-bold"><?= $todayLog && $todayLog['check_out'] ? e(date('H:i', strtotime($todayLog['check_out']))) : '--:--' ?></div>
                            </div>
                        </div>
                        <?php if ($todayLog): ?>
                            <?php $todayStatus = getStatusMeta(detectDayStatus(date('Y-m-d'), $todayLog, $leaveMap, $holidayMap)); ?>
                            <div class="mb-3 text-center"><span class="badge bg-<?= $todayStatus['class'] ?> px-3 py-2"><?= e($todayStatus['text']) ?></span></div>
                        <?php endif; ?>
                        <form method="post" class="d-grid gap-2">
                            <?= csrf_input() ?>
                            <button class="btn btn-success" type="submit" name="action" value="check_in" <?= $todayLog && !empty($todayLog['check_in']) ? 'disabled' : '' ?>>Check-in thủ công</button>
                            <button class="btn btn-danger" type="submit" name="action" value="check_out" <?= !$todayLog || empty($todayLog['check_in']) || !empty($todayLog['check_out']) ? 'disabled' : '' ?>>Check-out thủ công</button>
                        </form>
                        <?php if ($todayLog && !empty($todayLog['shift_name'])): ?>
                            <div class="mt-3 small text-muted">Ca làm hôm nay: <?= e($todayLog['shift_name']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="row g-3">
                    <div class="col-md-3"><div class="card shadow-sm border-0 text-center"><div class="card-body"><div class="text-muted small">Có mặt</div><div class="fs-4 fw-bold text-success"><?= $presentCount ?></div></div></div></div>
                    <div class="col-md-3"><div class="card shadow-sm border-0 text-center"><div class="card-body"><div class="text-muted small">Đi trễ</div><div class="fs-4 fw-bold text-warning"><?= $lateCount ?></div></div></div></div>
                    <div class="col-md-3"><div class="card shadow-sm border-0 text-center"><div class="card-body"><div class="text-muted small">Về sớm</div><div class="fs-4 fw-bold text-info"><?= $earlyLeaveCount ?></div></div></div></div>
                    <div class="col-md-3"><div class="card shadow-sm border-0 text-center"><div class="card-body"><div class="text-muted small">Vắng</div><div class="fs-4 fw-bold text-danger"><?= $absentCount ?></div></div></div></div>
                </div>
                <div class="card shadow-sm border-0 mt-3">
                    <div class="card-header bg-white fw-semibold">Chú thích trạng thái</div>
                    <div class="card-body d-flex flex-wrap gap-2">
                        <?php foreach (['present', 'late', 'early_leave', 'leave', 'holiday', 'absent'] as $item): $meta = getStatusMeta($item); ?>
                            <span class="badge bg-<?= $meta['class'] ?> px-3 py-2"><?= e($meta['text']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong>Lịch chấm công tháng <?= $viewMonth ?>/<?= $viewYear ?></strong>
                <span class="text-muted small">Tổng <?= $daysInMonth ?> ngày</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                                    <?php $date = sprintf('%04d-%02d-%02d', $viewYear, $viewMonth, $day); ?>
                                    <th class="text-center <?= $date === date('Y-m-d') ? 'table-primary' : '' ?>" style="width: 110px;">
                                        <div><?= $day ?></div>
                                        <small class="text-muted"><?= ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'][date('w', strtotime($date))] ?></small>
                                    </th>
                                <?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                                    <?php
                                    $date = sprintf('%04d-%02d-%02d', $viewYear, $viewMonth, $day);
                                    $log = $monthLogs[$date] ?? null;
                                    $statusKey = detectDayStatus($date, $log, $leaveMap, $holidayMap);
                                    $meta = getStatusMeta($statusKey);
                                    ?>
                                    <td class="text-center small <?= $date === date('Y-m-d') ? 'table-primary-subtle' : '' ?>">
                                        <span class="badge bg-<?= $meta['class'] ?> mb-2"><?= e($meta['text']) ?></span>
                                        <?php if ($log && !empty($log['check_in'])): ?>
                                            <div>Vào: <?= e(date('H:i', strtotime($log['check_in']))) ?></div>
                                        <?php endif; ?>
                                        <?php if ($log && !empty($log['check_out'])): ?>
                                            <div>Ra: <?= e(date('H:i', strtotime($log['check_out']))) ?></div>
                                        <?php endif; ?>
                                        <?php if (isset($holidayMap[$date])): ?>
                                            <div class="text-danger"><?= e($holidayMap[$date]) ?></div>
                                        <?php elseif (isset($leaveMap[$date])): ?>
                                            <div class="text-primary"><?= e($leaveMap[$date]) ?></div>
                                        <?php endif; ?>
                                    </td>
                                <?php endfor; ?>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
