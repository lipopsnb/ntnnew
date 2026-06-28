<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireRole('director', 'manager', 'production');

$pdo = getDBConnection();
$user = currentUser();
$errors = [];
$viewMonth = max(1, min(12, (int) ($_GET['month'] ?? date('n'))));
$viewYear = max(2020, min(2100, (int) ($_GET['year'] ?? date('Y'))));
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $viewMonth, $viewYear);
$monthStart = sprintf('%04d-%02d-01', $viewYear, $viewMonth);
$monthEnd = sprintf('%04d-%02d-%02d', $viewYear, $viewMonth, $daysInMonth);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc không hợp lệ.');
        header('Location: /ntn_erp/modules/attendance/shift_schedule.php');
        exit();
    }

    $userId = (int) ($_POST['user_id'] ?? 0);
    $shiftId = (int) ($_POST['shift_id'] ?? 0);
    $workDate = $_POST['work_date'] ?? '';
    $note = trim($_POST['note'] ?? '');

    if ($userId <= 0 || $shiftId <= 0 || $workDate === '') $errors[] = 'Vui lòng chọn nhân viên, ca làm và ngày làm việc.';

    if (!$errors) {
        $stmt = $pdo->prepare(
            "INSERT INTO shift_schedules (user_id, shift_id, work_date, note, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE shift_id = VALUES(shift_id), note = VALUES(note), created_by = VALUES(created_by)"
        );
        $stmt->execute([$userId, $shiftId, $workDate, $note, (int) $user['id']]);
        setFlash('success', 'Đã lưu lịch ca.');
        header('Location: /ntn_erp/modules/attendance/shift_schedule.php?month=' . date('n', strtotime($workDate)) . '&year=' . date('Y', strtotime($workDate)));
        exit();
    }
}

$users = $pdo->query("SELECT id, employee_code, full_name FROM users WHERE is_active = 1 ORDER BY full_name ASC")->fetchAll();
$shifts = $pdo->query("SELECT id, shift_code, shift_name, color FROM work_shifts WHERE is_active = 1 ORDER BY shift_name ASC")->fetchAll();
$scheduleStmt = $pdo->prepare(
    "SELECT ss.*, u.full_name, u.employee_code, ws.shift_code, ws.shift_name, ws.color
     FROM shift_schedules ss
     INNER JOIN users u ON u.id = ss.user_id
     INNER JOIN work_shifts ws ON ws.id = ss.shift_id
     WHERE ss.work_date BETWEEN ? AND ?
     ORDER BY u.full_name ASC, ss.work_date ASC"
);
$scheduleStmt->execute([$monthStart, $monthEnd]);
$scheduleRows = $scheduleStmt->fetchAll();
$scheduleMap = [];
foreach ($scheduleRows as $row) {
    $scheduleMap[(int) $row['user_id']][$row['work_date']] = $row;
}

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="main-content">
    <div class="container-fluid py-4">
        <?php showFlash(); ?>
        <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h4 class="mb-1">Lịch ca tháng <?= $viewMonth ?>/<?= $viewYear ?></h4>
                <p class="text-muted mb-0">Hiển thị lịch phân ca theo từng nhân viên</p>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="?month=<?= $viewMonth === 1 ? 12 : $viewMonth - 1 ?>&year=<?= $viewMonth === 1 ? $viewYear - 1 : $viewYear ?>">&laquo; Tháng trước</a>
                <a class="btn btn-outline-secondary btn-sm" href="?month=<?= $viewMonth === 12 ? 1 : $viewMonth + 1 ?>&year=<?= $viewMonth === 12 ? $viewYear + 1 : $viewYear ?>">Tháng sau &raquo;</a>
            </div>
        </div>
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary text-white">Lập lịch ca</div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <?= csrf_input() ?>
                            <div class="col-12"><label class="form-label">Nhân viên</label><select class="form-select" name="user_id" required><option value="">Chọn nhân viên</option><?php foreach ($users as $row): ?><option value="<?= (int) $row['id'] ?>"><?= e($row['employee_code'] . ' - ' . $row['full_name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-12"><label class="form-label">Ca làm việc</label><select class="form-select" name="shift_id" required><option value="">Chọn ca</option><?php foreach ($shifts as $row): ?><option value="<?= (int) $row['id'] ?>"><?= e($row['shift_code'] . ' - ' . $row['shift_name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-12"><label class="form-label">Ngày làm việc</label><input class="form-control" type="date" name="work_date" value="<?= e($_POST['work_date'] ?? $monthStart) ?>" required></div>
                            <div class="col-12"><label class="form-label">Ghi chú</label><textarea class="form-control" name="note" rows="3"><?= e($_POST['note'] ?? '') ?></textarea></div>
                            <div class="col-12 d-grid"><button class="btn btn-primary" type="submit">Lưu lịch ca</button></div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white fw-semibold">Bảng lịch ca</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle mb-0 small">
                                <thead class="table-light">
                                    <tr>
                                        <th style="min-width: 180px;">Nhân viên</th>
                                        <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                                            <th class="text-center" style="min-width: 85px;"><?= $day ?></th>
                                        <?php endfor; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$users): ?><tr><td colspan="<?= $daysInMonth + 1 ?>" class="text-center text-muted py-4">Không có nhân viên.</td></tr><?php endif; ?>
                                    <?php foreach ($users as $row): ?>
                                        <tr>
                                            <td><div class="fw-semibold"><?= e($row['full_name']) ?></div><div class="text-muted"><?= e($row['employee_code']) ?></div></td>
                                            <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                                                <?php $date = sprintf('%04d-%02d-%02d', $viewYear, $viewMonth, $day); $schedule = $scheduleMap[(int) $row['id']][$date] ?? null; ?>
                                                <td class="text-center">
                                                    <?php if ($schedule): ?>
                                                        <span class="badge" style="background: <?= e($schedule['color']) ?>;" title="<?= e($schedule['note']) ?>"><?= e($schedule['shift_code']) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endfor; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
