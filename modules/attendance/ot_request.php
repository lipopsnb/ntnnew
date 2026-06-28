<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireLogin();

$pdo = getDBConnection();
$user = currentUser();
$errors = [];
$otTypeLabels = ['weekday' => 'Ngày thường', 'weekend' => 'Cuối tuần', 'holiday' => 'Ngày lễ'];

function calculateOtHours(string $date, string $startTime, string $endTime): float {
    $start = strtotime($date . ' ' . $startTime);
    $end = strtotime($date . ' ' . $endTime);
    if ($end <= $start) {
        $end += 86400;
    }
    return round(($end - $start) / 3600, 2);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc không hợp lệ.');
        header('Location: /ntn_erp/modules/attendance/ot_request.php');
        exit();
    }

    $otDate = $_POST['ot_date'] ?? '';
    $startTime = $_POST['start_time'] ?? '';
    $endTime = $_POST['end_time'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    $otType = $_POST['ot_type'] ?? '';
    $shiftId = $_POST['shift_id'] !== '' ? (int) $_POST['shift_id'] : null;

    if (!$user['department_id']) $errors[] = 'Tài khoản chưa có phòng ban.';
    if (!$otDate || !$startTime || !$endTime) $errors[] = 'Vui lòng nhập đủ ngày và giờ OT.';
    if (!isset($otTypeLabels[$otType])) $errors[] = 'Loại OT không hợp lệ.';
    if ($reason === '') $errors[] = 'Vui lòng nhập lý do OT.';

    if (!$errors) {
        $hours = calculateOtHours($otDate, $startTime, $endTime);
        if ($hours <= 0) $errors[] = 'Số giờ OT phải lớn hơn 0.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO overtime_requests
                    (user_id, department_id, ot_date, start_time, end_time, hours, actual_hours, reason, ot_type, shift_id, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, 'pending', NOW())"
            );
            $stmt->execute([(int) $user['id'], (int) $user['department_id'], $otDate, $startTime, $endTime, $hours, $reason, $otType, $shiftId]);
            $requestId = (int) $pdo->lastInsertId();

            $managerStmt = $pdo->query(
                "SELECT u.id
                 FROM users u
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE u.is_active = 1 AND r.name IN ('director', 'manager')"
            );
            $notifyStmt = $pdo->prepare(
                "INSERT INTO notifications (user_id, title, message, type, reference_id, is_read, created_at)
                 VALUES (?, ?, ?, ?, ?, 0, NOW())"
            );
            $message = $user['full_name'] . ' đăng ký OT ngày ' . formatDate($otDate) . ' từ ' . $startTime . ' đến ' . $endTime . '.';
            foreach ($managerStmt->fetchAll() as $manager) {
                $notifyStmt->execute([(int) $manager['id'], 'Đăng ký OT mới', $message, 'ot_request', $requestId]);
            }
            $pdo->commit();
            setFlash('success', 'Đã gửi yêu cầu OT thành công.');
            header('Location: /ntn_erp/modules/attendance/ot_request.php');
            exit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Không thể lưu yêu cầu OT.';
        }
    }
}

$shifts = $pdo->query("SELECT id, shift_code, shift_name FROM work_shifts ORDER BY shift_name ASC")->fetchAll();
$listStmt = $pdo->prepare(
    "SELECT ot.*, ws.shift_name, u.full_name AS approver_name
     FROM overtime_requests ot
     LEFT JOIN work_shifts ws ON ws.id = ot.shift_id
     LEFT JOIN users u ON u.id = ot.approved_by
     WHERE ot.user_id = ?
     ORDER BY ot.created_at DESC"
);
$listStmt->execute([(int) $user['id']]);
$requests = $listStmt->fetchAll();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="main-content">
    <div class="container-fluid py-4">
        <?php showFlash(); ?>
        <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary text-white">Tạo yêu cầu tăng ca</div>
                    <div class="card-body">
                        <form method="post" class="row g-3" id="otForm">
                            <?= csrf_input() ?>
                            <div class="col-12"><label class="form-label">Ngày OT</label><input class="form-control" type="date" name="ot_date" value="<?= e($_POST['ot_date'] ?? '') ?>" required></div>
                            <div class="col-md-6"><label class="form-label">Giờ bắt đầu</label><input class="form-control" type="time" name="start_time" value="<?= e($_POST['start_time'] ?? '') ?>" required></div>
                            <div class="col-md-6"><label class="form-label">Giờ kết thúc</label><input class="form-control" type="time" name="end_time" value="<?= e($_POST['end_time'] ?? '') ?>" required></div>
                            <div class="col-12">
                                <label class="form-label">Loại OT</label>
                                <select class="form-select" name="ot_type" required>
                                    <?php foreach ($otTypeLabels as $value => $label): ?>
                                        <option value="<?= e($value) ?>" <?= ($_POST['ot_type'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Ca làm việc (nếu có)</label>
                                <select class="form-select" name="shift_id">
                                    <option value="">Không chọn</option>
                                    <?php foreach ($shifts as $shift): ?>
                                        <option value="<?= (int) $shift['id'] ?>" <?= (string) ($_POST['shift_id'] ?? '') === (string) $shift['id'] ? 'selected' : '' ?>><?= e($shift['shift_code'] . ' - ' . $shift['shift_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12"><label class="form-label">Lý do</label><textarea class="form-control" name="reason" rows="4" required><?= e($_POST['reason'] ?? '') ?></textarea></div>
                            <div class="col-12"><div class="alert alert-light border mb-0">Số giờ OT: <strong id="otHoursPreview">0</strong> giờ</div></div>
                            <div class="col-12 d-grid"><button class="btn btn-primary" type="submit">Gửi yêu cầu</button></div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white fw-semibold">Yêu cầu OT của tôi</div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>Ngày</th><th>Thời gian</th><th class="text-center">Giờ OT</th><th>Loại</th><th>Lý do</th><th>Trạng thái</th></tr></thead>
                            <tbody>
                                <?php if (!$requests): ?><tr><td colspan="6" class="text-center text-muted py-4">Chưa có yêu cầu OT nào.</td></tr><?php endif; ?>
                                <?php foreach ($requests as $request): ?>
                                    <?php $badge = $request['status'] === 'approved' ? 'success' : ($request['status'] === 'rejected' ? 'danger' : 'warning text-dark'); ?>
                                    <tr>
                                        <td><?= e(formatDate($request['ot_date'])) ?></td>
                                        <td><?= e(substr($request['start_time'], 0, 5) . ' - ' . substr($request['end_time'], 0, 5)) ?></td>
                                        <td class="text-center"><?= e($request['hours']) ?></td>
                                        <td><?= e($otTypeLabels[$request['ot_type']] ?? $request['ot_type']) ?></td>
                                        <td><?= e($request['reason']) ?></td>
                                        <td><span class="badge bg-<?= $badge ?>"><?= e($request['status']) ?></span><?php if (!empty($request['reject_reason'])): ?><div class="small text-muted mt-1"><?= e($request['reject_reason']) ?></div><?php endif; ?></td>
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
<script>
(function () {
    const dateInput = document.querySelector('[name="ot_date"]');
    const startInput = document.querySelector('[name="start_time"]');
    const endInput = document.querySelector('[name="end_time"]');
    const preview = document.getElementById('otHoursPreview');
    function updateHours() {
        if (!dateInput.value || !startInput.value || !endInput.value) {
            preview.textContent = '0';
            return;
        }
        const start = new Date(dateInput.value + 'T' + startInput.value + ':00');
        let end = new Date(dateInput.value + 'T' + endInput.value + ':00');
        if (end <= start) end.setDate(end.getDate() + 1);
        preview.textContent = ((end - start) / 3600000).toFixed(2);
    }
    [dateInput, startInput, endInput].forEach(el => el.addEventListener('change', updateHours));
    updateHours();
})();
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
