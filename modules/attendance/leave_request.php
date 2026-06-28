<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireLogin();

$pdo = getDBConnection();
$user = currentUser();
$leaveLabels = ['annual' => 'Nghỉ phép năm', 'sick' => 'Nghỉ ốm', 'unpaid' => 'Nghỉ không lương', 'other' => 'Khác'];
$errors = [];

function leaveHolidaySet(PDO $pdo, string $startDate, string $endDate): array {
    $stmt = $pdo->prepare("SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?");
    $stmt->execute([$startDate, $endDate]);
    return array_flip(array_column($stmt->fetchAll(), 'holiday_date'));
}

function calculateLeaveDaysReal(PDO $pdo, string $startDate, string $endDate): int {
    $holidaySet = leaveHolidaySet($pdo, $startDate, $endDate);
    $cursor = new DateTime($startDate);
    $limit = new DateTime($endDate);
    $days = 0;
    while ($cursor <= $limit) {
        $date = $cursor->format('Y-m-d');
        $dow = (int) $cursor->format('N');
        if ($dow < 6 && !isset($holidaySet[$date])) {
            $days++;
        }
        $cursor->modify('+1 day');
    }
    return $days;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc không hợp lệ.');
        header('Location: /ntn_erp/modules/attendance/leave_request.php');
        exit();
    }

    $leaveType = $_POST['leave_type'] ?? '';
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if (!isset($leaveLabels[$leaveType])) $errors[] = 'Loại nghỉ không hợp lệ.';
    if (!$startDate || !$endDate) $errors[] = 'Vui lòng chọn ngày bắt đầu và ngày kết thúc.';
    if ($startDate && $endDate && $endDate < $startDate) $errors[] = 'Ngày kết thúc phải lớn hơn hoặc bằng ngày bắt đầu.';
    if ($reason === '') $errors[] = 'Vui lòng nhập lý do nghỉ.';

    if (!$errors) {
        $totalDays = calculateLeaveDaysReal($pdo, $startDate, $endDate);
        if ($totalDays <= 0) {
            $errors[] = 'Khoảng thời gian đã chọn không có ngày làm việc hợp lệ.';
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, total_days, reason, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())"
                );
                $stmt->execute([(int) $user['id'], $leaveType, $startDate, $endDate, $totalDays, $reason]);
                $requestId = (int) $pdo->lastInsertId();

                $notifyStmt = $pdo->prepare(
                    "INSERT INTO notifications (user_id, title, message, type, reference_id, is_read, created_at)
                     VALUES (?, ?, ?, ?, ?, 0, NOW())"
                );
                $managerStmt = $pdo->query(
                    "SELECT u.id
                     FROM users u
                     INNER JOIN roles r ON r.id = u.role_id
                     WHERE u.is_active = 1 AND r.name IN ('director', 'manager')"
                );
                $message = $user['full_name'] . ' gửi đơn nghỉ từ ' . formatDate($startDate) . ' đến ' . formatDate($endDate) . '.';
                foreach ($managerStmt->fetchAll() as $manager) {
                    $notifyStmt->execute([(int) $manager['id'], 'Đơn nghỉ phép mới', $message, 'leave_request', $requestId]);
                }
                $pdo->commit();
                setFlash('success', 'Đã tạo đơn nghỉ phép thành công.');
                header('Location: /ntn_erp/modules/attendance/leave_request.php');
                exit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Không thể lưu đơn nghỉ phép.';
            }
        }
    }
}

$listStmt = $pdo->prepare(
    "SELECT lr.*, u.full_name AS approver_name
     FROM leave_requests lr
     LEFT JOIN users u ON u.id = lr.approved_by
     WHERE lr.user_id = ?
     ORDER BY lr.created_at DESC"
);
$listStmt->execute([(int) $user['id']]);
$requests = $listStmt->fetchAll();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="main-content">
    <div class="container-fluid py-4">
        <?php showFlash(); ?>
        <?php if ($errors): ?>
            <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary text-white">Tạo đơn nghỉ phép</div>
                    <div class="card-body">
                        <form method="post" class="row g-3" id="leaveForm">
                            <?= csrf_input() ?>
                            <div class="col-12">
                                <label class="form-label">Loại nghỉ</label>
                                <select class="form-select" name="leave_type" required>
                                    <?php foreach ($leaveLabels as $value => $label): ?>
                                        <option value="<?= e($value) ?>" <?= ($_POST['leave_type'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Từ ngày</label>
                                <input class="form-control" type="date" name="start_date" value="<?= e($_POST['start_date'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Đến ngày</label>
                                <input class="form-control" type="date" name="end_date" value="<?= e($_POST['end_date'] ?? '') ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Lý do</label>
                                <textarea class="form-control" name="reason" rows="4" required><?= e($_POST['reason'] ?? '') ?></textarea>
                            </div>
                            <div class="col-12">
                                <div class="alert alert-light border mb-0">Số ngày nghỉ thực tế: <strong id="leaveDaysPreview">0</strong> ngày</div>
                            </div>
                            <div class="col-12 d-grid">
                                <button class="btn btn-primary" type="submit">Gửi đơn</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white fw-semibold">Đơn nghỉ phép của tôi</div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Loại nghỉ</th>
                                    <th>Từ ngày</th>
                                    <th>Đến ngày</th>
                                    <th class="text-center">Số ngày</th>
                                    <th>Lý do</th>
                                    <th>Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$requests): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">Chưa có đơn nghỉ phép nào.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($requests as $request): ?>
                                    <?php $badge = $request['status'] === 'approved' ? 'success' : ($request['status'] === 'rejected' ? 'danger' : 'warning text-dark'); ?>
                                    <tr>
                                        <td><?= e($leaveLabels[$request['leave_type']] ?? $request['leave_type']) ?></td>
                                        <td><?= e(formatDate($request['start_date'])) ?></td>
                                        <td><?= e(formatDate($request['end_date'])) ?></td>
                                        <td class="text-center"><?= e($request['total_days']) ?></td>
                                        <td><?= e($request['reason']) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $badge ?>"><?= e($request['status']) ?></span>
                                            <?php if (!empty($request['reject_reason'])): ?><div class="small text-muted mt-1"><?= e($request['reject_reason']) ?></div><?php endif; ?>
                                        </td>
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
    const startInput = document.querySelector('[name="start_date"]');
    const endInput = document.querySelector('[name="end_date"]');
    const preview = document.getElementById('leaveDaysPreview');
    function updatePreview() {
        if (!startInput.value || !endInput.value || endInput.value < startInput.value) {
            preview.textContent = '0';
            return;
        }
        let start = new Date(startInput.value + 'T00:00:00');
        let end = new Date(endInput.value + 'T00:00:00');
        let total = 0;
        for (let current = new Date(start); current <= end; current.setDate(current.getDate() + 1)) {
            const day = current.getDay();
            if (day !== 0 && day !== 6) total++;
        }
        preview.textContent = total;
    }
    startInput.addEventListener('change', updatePreview);
    endInput.addEventListener('change', updatePreview);
    updatePreview();
})();
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
