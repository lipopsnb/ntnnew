<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireRole('director', 'manager');

$pdo = getDBConnection();
$user = currentUser();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc không hợp lệ.');
        header('Location: /ntn_erp/modules/attendance/shift_assign.php');
        exit();
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM employee_shifts WHERE id = ?");
        $stmt->execute([(int) ($_POST['assignment_id'] ?? 0)]);
        setFlash('success', 'Đã xóa phân ca.');
        header('Location: /ntn_erp/modules/attendance/shift_assign.php');
        exit();
    }

    $userId = (int) ($_POST['user_id'] ?? 0);
    $shiftId = (int) ($_POST['shift_id'] ?? 0);
    $effectiveDate = $_POST['effective_date'] ?? '';
    $endDate = $_POST['end_date'] ?: null;

    if ($userId <= 0 || $shiftId <= 0 || $effectiveDate === '') $errors[] = 'Vui lòng chọn đầy đủ nhân viên, ca làm và ngày hiệu lực.';
    if ($endDate && $endDate < $effectiveDate) $errors[] = 'Ngày kết thúc phải lớn hơn hoặc bằng ngày hiệu lực.';

    if (!$errors) {
        $stmt = $pdo->prepare(
            "INSERT INTO employee_shifts (user_id, shift_id, effective_date, end_date, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$userId, $shiftId, $effectiveDate, $endDate, (int) $user['id']]);
        setFlash('success', 'Đã phân ca cho nhân viên.');
        header('Location: /ntn_erp/modules/attendance/shift_assign.php');
        exit();
    }
}

$users = $pdo->query("SELECT id, employee_code, full_name FROM users WHERE is_active = 1 ORDER BY full_name ASC")->fetchAll();
$shifts = $pdo->query("SELECT id, shift_code, shift_name FROM work_shifts WHERE is_active = 1 ORDER BY shift_name ASC")->fetchAll();
$assignments = $pdo->query(
    "SELECT es.*, u.employee_code, u.full_name, ws.shift_name
     FROM employee_shifts es
     INNER JOIN users u ON u.id = es.user_id
     INNER JOIN work_shifts ws ON ws.id = es.shift_id
     ORDER BY es.effective_date DESC, u.full_name ASC"
)->fetchAll();

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
                    <div class="card-header bg-primary text-white">Phân công ca</div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="assign">
                            <div class="col-12"><label class="form-label">Nhân viên</label><select class="form-select" name="user_id" required><option value="">Chọn nhân viên</option><?php foreach ($users as $row): ?><option value="<?= (int) $row['id'] ?>"><?= e($row['employee_code'] . ' - ' . $row['full_name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-12"><label class="form-label">Ca làm việc</label><select class="form-select" name="shift_id" required><option value="">Chọn ca làm việc</option><?php foreach ($shifts as $row): ?><option value="<?= (int) $row['id'] ?>"><?= e($row['shift_code'] . ' - ' . $row['shift_name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6"><label class="form-label">Ngày hiệu lực</label><input class="form-control" type="date" name="effective_date" value="<?= e($_POST['effective_date'] ?? date('Y-m-d')) ?>" required></div>
                            <div class="col-md-6"><label class="form-label">Ngày kết thúc</label><input class="form-control" type="date" name="end_date" value="<?= e($_POST['end_date'] ?? '') ?>"></div>
                            <div class="col-12 d-grid"><button class="btn btn-primary" type="submit">Lưu phân ca</button></div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white fw-semibold">Phân công hiện tại</div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>Nhân viên</th><th>Ca</th><th>Hiệu lực</th><th>Kết thúc</th><th class="text-end">Thao tác</th></tr></thead>
                            <tbody>
                                <?php if (!$assignments): ?><tr><td colspan="5" class="text-center text-muted py-4">Chưa có phân công ca nào.</td></tr><?php endif; ?>
                                <?php foreach ($assignments as $assignment): ?>
                                    <tr>
                                        <td><div class="fw-semibold"><?= e($assignment['full_name']) ?></div><div class="small text-muted"><?= e($assignment['employee_code']) ?></div></td>
                                        <td><?= e($assignment['shift_name']) ?></td>
                                        <td><?= e(formatDate($assignment['effective_date'])) ?></td>
                                        <td><?= $assignment['end_date'] ? e(formatDate($assignment['end_date'])) : '<span class="text-muted">Không giới hạn</span>' ?></td>
                                        <td class="text-end"><form method="post" onsubmit="return confirm('Xóa phân công này?');"><?= csrf_input() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>"><button class="btn btn-outline-danger btn-sm" type="submit">Xóa</button></form></td>
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
