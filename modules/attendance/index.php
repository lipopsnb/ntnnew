<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once __DIR__ . '/helpers.php';

requireLogin();

$pageTitle = 'Chấm công cá nhân';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => '/ntn_erp/index.php'],
    ['label' => 'Chấm công'],
];

$month = max(1, min(12, (int) ($_GET['month'] ?? date('n'))));
$year = max(2020, min(2100, (int) ($_GET['year'] ?? date('Y'))));
$userId = currentUserId();
$today = date('Y-m-d');
$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEnd = date('Y-m-t', strtotime($monthStart));
$attendanceReady = tableExists($pdo, 'attendance_logs');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfOrAbort();

    if (($_POST['action'] ?? '') === 'mark_today') {
        if (!$attendanceReady) {
            setFlashMessage('danger', 'Thiếu bảng attendance_logs trong cơ sở dữ liệu.');
            redirect('/ntn_erp/modules/attendance/index.php?month=' . $month . '&year=' . $year);
        }

        $cols = attendanceColumns($pdo);
        $now = date('Y-m-d H:i:s');
        $currentRecordStmt = $pdo->prepare(sprintf('SELECT `%s` AS id, `%s` AS check_in, `%s` AS check_out FROM attendance_logs WHERE `%s` = ? AND `%s` = ? LIMIT 1', pickColumn($pdo, 'attendance_logs', ['id']) ?? 'id', $cols['check_in'], $cols['check_out'], $cols['user'], $cols['date']));
        $currentRecordStmt->execute([$userId, $today]);
        $currentRecord = $currentRecordStmt->fetch();

        if (!$currentRecord) {
            $status = date('H:i') > '08:05' ? 'late' : 'present';
            $stmt = $pdo->prepare(sprintf('INSERT INTO attendance_logs (`%s`, `%s`, `%s`, `%s`) VALUES (?, ?, ?, ?)', $cols['user'], $cols['date'], $cols['check_in'], $cols['status']));
            $stmt->execute([$userId, $today, $now, $status]);
            setFlashMessage('success', 'Đã chấm công vào cho hôm nay.');
        } elseif (empty($currentRecord['check_out'])) {
            $stmt = $pdo->prepare(sprintf('UPDATE attendance_logs SET `%s` = ? WHERE `%s` = ?', $cols['check_out'], pickColumn($pdo, 'attendance_logs', ['id']) ?? 'id'));
            $stmt->execute([$now, $currentRecord['id']]);
            setFlashMessage('success', 'Đã chấm công ra cho hôm nay.');
        } else {
            setFlashMessage('info', 'Hôm nay bạn đã chấm công đầy đủ.');
        }

        redirect('/ntn_erp/modules/attendance/index.php?month=' . $month . '&year=' . $year);
    }
}

$logsMap = getAttendanceLogMap($pdo, [$userId], $monthStart, $monthEnd)[$userId] ?? [];
$leaveMap = getApprovedLeaveMap($pdo, [$userId], $monthStart, $monthEnd)[$userId] ?? [];
$holidayMap = getHolidayMap($pdo, $monthStart, $monthEnd);
$daysInMonth = (int) date('t', strtotime($monthStart));
$firstWeekday = (int) date('N', strtotime($monthStart));
$todayLog = $logsMap[$today] ?? null;
$canCheckIn = date('Y-m', strtotime($today)) === date('Y-m', strtotime($monthStart)) && (!$todayLog || empty($todayLog['check_in']) || empty($todayLog['check_out']));
$calendarDays = [];
$tableRows = [];

for ($day = 1; $day <= $daysInMonth; $day++) {
    $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
    $state = inferAttendanceStatus($date, $logsMap[$date] ?? null, $leaveMap[$date] ?? null, $holidayMap[$date] ?? null);
    $meta = attendanceStatusMeta($state['status']);
    $calendarDays[$date] = ['date' => $date, 'day' => $day, 'state' => $state, 'meta' => $meta];
    $tableRows[] = [
        'date' => $date,
        'weekday' => date('l', strtotime($date)),
        'check_in' => $state['check_in'],
        'check_out' => $state['check_out'],
        'status' => $state['status'],
        'meta' => $meta,
        'note' => $state['note'],
    ];
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Chấm công cá nhân</h1>
        <p class="text-muted mb-0">Theo dõi giờ vào/ra và trạng thái chấm công theo từng ngày.</p>
    </div>
    <?php if ($canCheckIn): ?>
        <form method="post" class="no-print">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="mark_today">
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-fingerprint me-2"></i><?= $todayLog && empty($todayLog['check_out']) ? 'Chấm công ra' : 'Chấm công hôm nay' ?>
            </button>
        </form>
    <?php endif; ?>
</div>

<div class="card content-card mb-4 no-print">
    <div class="card-body">
        <form class="row g-3 align-items-end" method="get">
            <div class="col-md-3">
                <label class="form-label">Tháng</label>
                <select name="month" class="form-select">
                    <?php foreach (monthOptions() as $value => $label): ?>
                        <option value="<?= $value ?>" <?= $value === $month ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Năm</label>
                <select name="year" class="form-select">
                    <?php foreach (buildYearOptions() as $optionYear): ?>
                        <option value="<?= $optionYear ?>" <?= $optionYear === $year ? 'selected' : '' ?>><?= $optionYear ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-outline-primary w-100">Xem dữ liệu</button>
            </div>
        </form>
    </div>
</div>

<?php if (!$attendanceReady): ?>
    <div class="alert alert-warning">Chưa tìm thấy bảng <code>attendance_logs</code>. Vui lòng tạo schema trước khi sử dụng.</div>
<?php endif; ?>

<div class="card content-card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h5 mb-0">Lịch chấm công tháng <?= $month ?>/<?= $year ?></h2>
            <div class="small text-muted">Hiển thị theo trạng thái: Có mặt / Đi trễ / Vắng / Nghỉ phép / Ngày lễ</div>
        </div>
        <div class="calendar-grid fw-semibold text-muted small mb-2">
            <?php foreach (['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'CN'] as $weekday): ?>
                <div class="px-1"><?= e($weekday) ?></div>
            <?php endforeach; ?>
        </div>
        <div class="calendar-grid">
            <?php for ($blank = 1; $blank < $firstWeekday; $blank++): ?>
                <div class="calendar-cell is-empty"></div>
            <?php endfor; ?>
            <?php foreach ($calendarDays as $item): ?>
                <div class="calendar-cell <?= e($item['meta']['cell']) ?>">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="fw-bold"><?= $item['day'] ?></span>
                        <span class="badge bg-<?= e($item['meta']['class']) ?>"><?= e($item['meta']['label']) ?></span>
                    </div>
                    <div class="small text-muted">Vào: <?= $item['state']['check_in'] ? e(date('H:i', strtotime($item['state']['check_in']))) : '-' ?></div>
                    <div class="small text-muted">Ra: <?= $item['state']['check_out'] ? e(date('H:i', strtotime($item['state']['check_out']))) : '-' ?></div>
                    <?php if (!empty($item['state']['note'])): ?>
                        <div class="small mt-2 fw-medium"><?= e($item['state']['note']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card content-card">
    <div class="card-body">
        <h2 class="h5 mb-3">Chi tiết theo ngày</h2>
        <div class="table-responsive">
            <table class="table align-middle table-hover">
                <thead class="table-light">
                <tr>
                    <th>Ngày</th>
                    <th>Thứ</th>
                    <th>Giờ vào</th>
                    <th>Giờ ra</th>
                    <th>Trạng thái</th>
                    <th>Ghi chú</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($tableRows as $row): ?>
                    <tr>
                        <td><?= e(formatDateVN($row['date'])) ?></td>
                        <td><?= e($row['weekday']) ?></td>
                        <td><?= $row['check_in'] ? e(date('H:i', strtotime($row['check_in']))) : '-' ?></td>
                        <td><?= $row['check_out'] ? e(date('H:i', strtotime($row['check_out']))) : '-' ?></td>
                        <td><span class="badge bg-<?= e($row['meta']['class']) ?>"><?= e($row['meta']['label']) ?></span></td>
                        <td><?= e($row['note'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
