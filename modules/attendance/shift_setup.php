<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireRole('director', 'accountant', 'manager');

$pdo = getDBConnection();
$user = currentUser();
$errors = [];
$editShift = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc không hợp lệ.');
        header('Location: /ntn_erp/modules/attendance/shift_setup.php');
        exit();
    }

    $action = $_POST['action'] ?? '';
    $shiftId = (int) ($_POST['shift_id'] ?? 0);

    if ($action === 'toggle') {
        $stmt = $pdo->prepare("UPDATE work_shifts SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?");
        $stmt->execute([$shiftId]);
        setFlash('success', 'Đã cập nhật trạng thái ca làm việc.');
        header('Location: /ntn_erp/modules/attendance/shift_setup.php');
        exit();
    }

    if ($action === 'delete') {
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM employee_shifts WHERE shift_id = ?");
        $checkStmt->execute([$shiftId]);
        if ((int) $checkStmt->fetchColumn() > 0) {
            setFlash('danger', 'Không thể xóa ca đang được sử dụng.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM work_shifts WHERE id = ?");
            $stmt->execute([$shiftId]);
            setFlash('success', 'Đã xóa ca làm việc.');
        }
        header('Location: /ntn_erp/modules/attendance/shift_setup.php');
        exit();
    }

    $form = [
        'shift_code' => trim($_POST['shift_code'] ?? ''),
        'shift_name' => trim($_POST['shift_name'] ?? ''),
        'start_time' => $_POST['start_time'] ?? '',
        'end_time' => $_POST['end_time'] ?? '',
        'late_threshold' => (int) ($_POST['late_threshold'] ?? 0),
        'break_minutes' => (int) ($_POST['break_minutes'] ?? 0),
        'work_hours' => (float) ($_POST['work_hours'] ?? 0),
        'ot_multiplier' => (float) ($_POST['ot_multiplier'] ?? 1.5),
        'weekend_multiplier' => (float) ($_POST['weekend_multiplier'] ?? 2.0),
        'holiday_multiplier' => (float) ($_POST['holiday_multiplier'] ?? 3.0),
        'color' => $_POST['color'] ?? '#0d6efd',
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    foreach (['shift_code' => 'Mã ca', 'shift_name' => 'Tên ca', 'start_time' => 'Giờ bắt đầu', 'end_time' => 'Giờ kết thúc'] as $key => $label) {
        if ($form[$key] === '') $errors[] = $label . ' không được để trống.';
    }

    $uniqueStmt = $pdo->prepare("SELECT COUNT(*) FROM work_shifts WHERE shift_code = ? AND id <> ?");
    $uniqueStmt->execute([$form['shift_code'], $shiftId]);
    if ((int) $uniqueStmt->fetchColumn() > 0) $errors[] = 'Mã ca đã tồn tại.';

    if (!$errors) {
        if ($action === 'create') {
            $stmt = $pdo->prepare(
                "INSERT INTO work_shifts
                    (shift_code, shift_name, start_time, end_time, late_threshold, break_minutes, work_hours, ot_multiplier, weekend_multiplier, holiday_multiplier, color, is_active, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$form['shift_code'], $form['shift_name'], $form['start_time'], $form['end_time'], $form['late_threshold'], $form['break_minutes'], $form['work_hours'], $form['ot_multiplier'], $form['weekend_multiplier'], $form['holiday_multiplier'], $form['color'], $form['is_active'], (int) $user['id']]);
            setFlash('success', 'Đã tạo ca làm việc mới.');
        } else {
            $stmt = $pdo->prepare(
                "UPDATE work_shifts
                 SET shift_code = ?, shift_name = ?, start_time = ?, end_time = ?, late_threshold = ?, break_minutes = ?, work_hours = ?, ot_multiplier = ?, weekend_multiplier = ?, holiday_multiplier = ?, color = ?, is_active = ?
                 WHERE id = ?"
            );
            $stmt->execute([$form['shift_code'], $form['shift_name'], $form['start_time'], $form['end_time'], $form['late_threshold'], $form['break_minutes'], $form['work_hours'], $form['ot_multiplier'], $form['weekend_multiplier'], $form['holiday_multiplier'], $form['color'], $form['is_active'], $shiftId]);
            setFlash('success', 'Đã cập nhật ca làm việc.');
        }
        header('Location: /ntn_erp/modules/attendance/shift_setup.php');
        exit();
    }
    $editShift = array_merge($form, ['id' => $shiftId]);
}

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM work_shifts WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $_GET['edit']]);
    $editShift = $stmt->fetch() ?: $editShift;
}

$shiftStmt = $pdo->query(
    "SELECT ws.*, (SELECT COUNT(*) FROM employee_shifts es WHERE es.shift_id = ws.id) AS assignment_count
     FROM work_shifts ws
     ORDER BY ws.shift_name ASC"
);
$shifts = $shiftStmt->fetchAll();
$formData = $editShift ?: ['shift_code' => '', 'shift_name' => '', 'start_time' => '', 'end_time' => '', 'late_threshold' => 0, 'break_minutes' => 0, 'work_hours' => 8, 'ot_multiplier' => 1.5, 'weekend_multiplier' => 2.0, 'holiday_multiplier' => 3.0, 'color' => '#0d6efd', 'is_active' => 1];

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
                    <div class="card-header bg-primary text-white"><?= $editShift ? 'Cập nhật ca làm việc' : 'Tạo ca làm việc' ?></div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="<?= $editShift ? 'update' : 'create' ?>">
                            <input type="hidden" name="shift_id" value="<?= (int) ($formData['id'] ?? 0) ?>">
                            <div class="col-md-5"><label class="form-label">Mã ca</label><input class="form-control" name="shift_code" value="<?= e($formData['shift_code']) ?>" required></div>
                            <div class="col-md-7"><label class="form-label">Tên ca</label><input class="form-control" name="shift_name" value="<?= e($formData['shift_name']) ?>" required></div>
                            <div class="col-md-6"><label class="form-label">Giờ bắt đầu</label><input class="form-control" type="time" name="start_time" value="<?= e(substr($formData['start_time'], 0, 5)) ?>" required></div>
                            <div class="col-md-6"><label class="form-label">Giờ kết thúc</label><input class="form-control" type="time" name="end_time" value="<?= e(substr($formData['end_time'], 0, 5)) ?>" required></div>
                            <div class="col-md-6"><label class="form-label">Ngưỡng đi trễ (phút)</label><input class="form-control" type="number" name="late_threshold" value="<?= e($formData['late_threshold']) ?>" min="0"></div>
                            <div class="col-md-6"><label class="form-label">Nghỉ giữa ca (phút)</label><input class="form-control" type="number" name="break_minutes" value="<?= e($formData['break_minutes']) ?>" min="0"></div>
                            <div class="col-md-6"><label class="form-label">Số giờ công</label><input class="form-control" type="number" step="0.01" name="work_hours" value="<?= e($formData['work_hours']) ?>"></div>
                            <div class="col-md-6"><label class="form-label">Hệ số OT</label><input class="form-control" type="number" step="0.01" name="ot_multiplier" value="<?= e($formData['ot_multiplier']) ?>"></div>
                            <div class="col-md-6"><label class="form-label">Hệ số cuối tuần</label><input class="form-control" type="number" step="0.01" name="weekend_multiplier" value="<?= e($formData['weekend_multiplier']) ?>"></div>
                            <div class="col-md-6"><label class="form-label">Hệ số ngày lễ</label><input class="form-control" type="number" step="0.01" name="holiday_multiplier" value="<?= e($formData['holiday_multiplier']) ?>"></div>
                            <div class="col-md-6"><label class="form-label">Màu hiển thị</label><input class="form-control form-control-color" type="color" name="color" value="<?= e($formData['color']) ?>"></div>
                            <div class="col-md-6 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="is_active" <?= (int) $formData['is_active'] === 1 ? 'checked' : '' ?>><label class="form-check-label">Đang hoạt động</label></div></div>
                            <div class="col-12 d-grid gap-2">
                                <button class="btn btn-primary" type="submit">Lưu ca làm việc</button>
                                <?php if ($editShift): ?><a class="btn btn-outline-secondary" href="/ntn_erp/modules/attendance/shift_setup.php">Hủy chỉnh sửa</a><?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white fw-semibold">Danh sách ca làm việc</div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>Mã ca</th><th>Tên ca</th><th>Thời gian</th><th>Giờ công</th><th>OT</th><th>Màu</th><th>Trạng thái</th><th class="text-end">Thao tác</th></tr></thead>
                            <tbody>
                                <?php if (!$shifts): ?><tr><td colspan="8" class="text-center text-muted py-4">Chưa có ca làm việc nào.</td></tr><?php endif; ?>
                                <?php foreach ($shifts as $shift): ?>
                                    <tr>
                                        <td><?= e($shift['shift_code']) ?></td>
                                        <td><?= e($shift['shift_name']) ?></td>
                                        <td><?= e(substr($shift['start_time'], 0, 5) . ' - ' . substr($shift['end_time'], 0, 5)) ?></td>
                                        <td><?= e($shift['work_hours']) ?></td>
                                        <td><?= e($shift['ot_multiplier']) ?></td>
                                        <td><span class="badge" style="background: <?= e($shift['color']) ?>;">&nbsp;&nbsp;&nbsp;</span></td>
                                        <td><span class="badge bg-<?= (int) $shift['is_active'] === 1 ? 'success' : 'secondary' ?>"><?= (int) $shift['is_active'] === 1 ? 'Hoạt động' : 'Ngừng' ?></span></td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm">
                                                <a class="btn btn-outline-primary" href="?edit=<?= (int) $shift['id'] ?>">Sửa</a>
                                                <form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="shift_id" value="<?= (int) $shift['id'] ?>"><button class="btn btn-outline-warning" type="submit">Bật/tắt</button></form>
                                                <form method="post" onsubmit="return confirm('Xóa ca này?');"><?= csrf_input() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="shift_id" value="<?= (int) $shift['id'] ?>"><button class="btn btn-outline-danger" type="submit" <?= (int) $shift['assignment_count'] > 0 ? 'disabled' : '' ?>>Xóa</button></form>
                                            </div>
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
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
