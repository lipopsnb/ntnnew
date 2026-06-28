<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireRole('director', 'accountant', 'manager');

$pdo = getDBConnection();
$currentUser = currentUser();
$errors = [];
$month = max(1, min(12, (int) ($_GET['month'] ?? date('n'))));
$year = max(2020, min(2100, (int) ($_GET['year'] ?? date('Y'))));
$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEnd = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc không hợp lệ.');
        header('Location: /ntn_erp/modules/kpi/assign.php');
        exit();
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'create_assignment') {
        $assignDate = $_POST['assign_date'] ?? '';
        $userId = (int) ($_POST['user_id'] ?? 0);
        $managerId = (int) ($_POST['manager_id'] ?? 0);
        $kpiTarget = (float) ($_POST['kpi_target'] ?? 0);
        $overBonusPct = (float) ($_POST['over_bonus_pct'] ?? 0);
        if (!$assignDate || $userId <= 0 || $managerId <= 0 || $kpiTarget <= 0) {
            $errors[] = 'Vui lòng nhập đầy đủ thông tin KPI.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO kpi_assignments (assign_date, user_id, manager_id, kpi_target, created_at, updated_at, over_bonus_pct) VALUES (?, ?, ?, ?, NOW(), NOW(), ?)");
            $stmt->execute([$assignDate, $userId, $managerId, $kpiTarget, $overBonusPct]);
            setFlash('success', 'Đã tạo phân bổ KPI.');
            header('Location: /ntn_erp/modules/kpi/assign.php?month=' . date('n', strtotime($assignDate)) . '&year=' . date('Y', strtotime($assignDate)));
            exit();
        }
    }

    if ($action === 'save_result') {
        $assignmentId = (int) ($_POST['kpi_assignment_id'] ?? 0);
        $actualQty = (float) ($_POST['actual_qty'] ?? 0);
        $salaryPerDay = (float) ($_POST['salary_per_day'] ?? 0);
        $salaryActual = (float) ($_POST['salary_actual'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if ($assignmentId <= 0) {
            $errors[] = 'Bản ghi KPI không hợp lệ.';
        } else {
            $checkStmt = $pdo->prepare("SELECT id FROM kpi_results WHERE kpi_assignment_id = ? LIMIT 1");
            $checkStmt->execute([$assignmentId]);
            $resultId = $checkStmt->fetchColumn();
            if ($resultId) {
                $stmt = $pdo->prepare("UPDATE kpi_results SET actual_qty = ?, salary_per_day = ?, salary_actual = ?, reason = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$actualQty, $salaryPerDay, $salaryActual, $reason ?: null, $resultId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO kpi_results (kpi_assignment_id, actual_qty, salary_per_day, salary_actual, is_deducted, reason, confirmed_by, confirmed_at, created_at, updated_at) VALUES (?, ?, ?, ?, 0, ?, ?, NOW(), NOW(), NOW())");
                $stmt->execute([$assignmentId, $actualQty, $salaryPerDay, $salaryActual, $reason ?: null, (int) $currentUser['id']]);
            }
            setFlash('success', 'Đã lưu kết quả KPI.');
            header('Location: /ntn_erp/modules/kpi/assign.php?month=' . $month . '&year=' . $year);
            exit();
        }
    }
}

$employees = $pdo->query("SELECT id, employee_code, full_name FROM users WHERE is_active = 1 ORDER BY full_name ASC")->fetchAll();
$managers = $pdo->query("SELECT u.id, u.full_name FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.name IN ('director', 'accountant', 'manager') ORDER BY u.full_name ASC")->fetchAll();
$assignmentsStmt = $pdo->prepare(
    "SELECT ka.*, emp.full_name AS employee_name, emp.employee_code, mgr.full_name AS manager_name,
            kr.actual_qty, kr.salary_per_day, kr.salary_actual, kr.reason AS result_reason
     FROM kpi_assignments ka
     INNER JOIN users emp ON emp.id = ka.user_id
     INNER JOIN users mgr ON mgr.id = ka.manager_id
     LEFT JOIN kpi_results kr ON kr.kpi_assignment_id = ka.id
     WHERE ka.assign_date BETWEEN ? AND ?
     ORDER BY ka.assign_date DESC, emp.full_name ASC"
);
$assignmentsStmt->execute([$monthStart, $monthEnd]);
$assignments = $assignmentsStmt->fetchAll();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="main-content">
    <div class="container-fluid py-4">
        <?php showFlash(); ?>
        <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-primary text-white">Phân bổ KPI</div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="create_assignment">
                            <div class="col-12"><label class="form-label">Ngày giao KPI</label><input class="form-control" type="date" name="assign_date" value="<?= e($_POST['assign_date'] ?? date('Y-m-d')) ?>" required></div>
                            <div class="col-12"><label class="form-label">Nhân viên</label><select class="form-select" name="user_id" required><option value="">Chọn nhân viên</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>"><?= e($employee['employee_code'] . ' - ' . $employee['full_name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-12"><label class="form-label">Quản lý</label><select class="form-select" name="manager_id" required><option value="">Chọn quản lý</option><?php foreach ($managers as $manager): ?><option value="<?= (int) $manager['id'] ?>" <?= (int) $currentUser['id'] === (int) $manager['id'] ? 'selected' : '' ?>><?= e($manager['full_name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6"><label class="form-label">KPI mục tiêu</label><input class="form-control" type="number" step="0.01" name="kpi_target" required></div>
                            <div class="col-md-6"><label class="form-label">% thưởng vượt</label><input class="form-control" type="number" step="0.01" name="over_bonus_pct" value="0"></div>
                            <div class="col-12 d-grid"><button class="btn btn-primary" type="submit">Lưu KPI</button></div>
                        </form>
                    </div>
                </div>
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white fw-semibold">Bộ lọc</div>
                    <div class="card-body">
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-6"><label class="form-label">Tháng</label><input class="form-control" type="number" name="month" min="1" max="12" value="<?= $month ?>"></div>
                            <div class="col-6"><label class="form-label">Năm</label><input class="form-control" type="number" name="year" value="<?= $year ?>"></div>
                            <div class="col-12 d-grid"><button class="btn btn-outline-primary" type="submit">Xem dữ liệu</button></div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white fw-semibold">Danh sách KPI và kết quả</div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>Ngày</th><th>Nhân viên</th><th>Quản lý</th><th class="text-end">KPI</th><th class="text-end">Kết quả</th><th class="text-end">Lương/ngày</th><th class="text-end">Lương thực tế</th></tr></thead>
                            <tbody>
                                <?php if (!$assignments): ?><tr><td colspan="7" class="text-center text-muted py-4">Chưa có dữ liệu KPI trong kỳ này.</td></tr><?php endif; ?>
                                <?php foreach ($assignments as $assignment): ?>
                                    <tr>
                                        <td><?= e(formatDate($assignment['assign_date'])) ?></td>
                                        <td><div class="fw-semibold"><?= e($assignment['employee_name']) ?></div><div class="text-muted small"><?= e($assignment['employee_code']) ?></div></td>
                                        <td><?= e($assignment['manager_name']) ?></td>
                                        <td class="text-end"><?= e($assignment['kpi_target']) ?></td>
                                        <td>
                                            <form method="post" class="row g-2 align-items-center">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="action" value="save_result">
                                                <input type="hidden" name="kpi_assignment_id" value="<?= (int) $assignment['id'] ?>">
                                                <div class="col-12"><input class="form-control form-control-sm" type="number" step="0.01" name="actual_qty" value="<?= e($assignment['actual_qty'] ?? '') ?>" placeholder="Sản lượng"></div>
                                                <div class="col-6"><input class="form-control form-control-sm" type="number" step="0.01" name="salary_per_day" value="<?= e($assignment['salary_per_day'] ?? '') ?>" placeholder="Lương/ngày"></div>
                                                <div class="col-6"><input class="form-control form-control-sm" type="number" step="0.01" name="salary_actual" value="<?= e($assignment['salary_actual'] ?? '') ?>" placeholder="Lương thực tế"></div>
                                                <div class="col-12"><input class="form-control form-control-sm" name="reason" value="<?= e($assignment['result_reason'] ?? '') ?>" placeholder="Ghi chú"></div>
                                                <div class="col-12 d-grid"><button class="btn btn-outline-primary btn-sm" type="submit">Lưu kết quả</button></div>
                                            </form>
                                        </td>
                                        <td class="text-end"><?= e($assignment['actual_qty'] ?? '-') ?></td>
                                        <td class="text-end"><?= e($assignment['salary_per_day'] ?? '-') ?></td>
                                        <td class="text-end"><?= e($assignment['salary_actual'] ?? '-') ?></td>
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
