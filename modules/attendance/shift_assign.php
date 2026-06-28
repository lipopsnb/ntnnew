<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once __DIR__ . '/helpers.php';

requireRole(['director', 'manager']);

$pageTitle = 'Phân công ca';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => '/ntn_erp/index.php'],
    ['label' => 'Phân công ca'],
];
$assignReady = (tableExists($pdo, 'shift_assignments') || tableExists($pdo, 'shift_assigns')) && tableExists($pdo, 'shifts');
$employees = fetchEmployees($pdo);
$shifts = fetchShiftList($pdo);
$filterEmployeeId = (int) ($_GET['employee_id'] ?? 0);
$filterDateFrom = $_GET['date_from'] ?? date('Y-m-01');
$filterDateTo = $_GET['date_to'] ?? date('Y-m-t');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $assignReady) {
    validateCsrfOrAbort();
    $action = $_POST['action'] ?? '';

    if ($action === 'assign_shift') {
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $shiftId = (int) ($_POST['shift_id'] ?? 0);
        $dateFrom = (string) ($_POST['date_from'] ?? '');
        $dateTo = (string) ($_POST['date_to'] ?? '');
        foreach (buildDatesInRange($dateFrom, $dateTo) as $date) {
            upsertShiftAssignment($pdo, $employeeId, $shiftId, $date);
        }
        setFlashMessage('success', 'Đã phân công ca cho nhân viên.');
    }

    if ($action === 'bulk_assign') {
        $employeeIds = array_map('intval', $_POST['employee_ids'] ?? []);
        $shiftId = (int) ($_POST['shift_id'] ?? 0);
        $dateFrom = (string) ($_POST['date_from'] ?? '');
        $dateTo = (string) ($_POST['date_to'] ?? '');
        foreach ($employeeIds as $employeeId) {
            foreach (buildDatesInRange($dateFrom, $dateTo) as $date) {
                upsertShiftAssignment($pdo, $employeeId, $shiftId, $date);
            }
        }
        setFlashMessage('success', 'Đã phân công hàng loạt.');
    }

    redirect('/ntn_erp/modules/attendance/shift_assign.php?employee_id=' . $filterEmployeeId . '&date_from=' . urlencode($filterDateFrom) . '&date_to=' . urlencode($filterDateTo));
}

$assignments = fetchShiftAssignments($pdo, $filterDateFrom, $filterDateTo, $filterEmployeeId ?: null);
$employeeMap = [];
foreach ($employees as $employee) {
    $employeeMap[$employee['id']] = $employee['display_name'];
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="mb-4">
    <h1 class="h3 mb-1">Phân công ca</h1>
    <p class="text-muted mb-0">Áp dụng ca cho từng nhân viên hoặc gán hàng loạt theo khoảng ngày.</p>
</div>
<?php if (!$assignReady): ?>
    <div class="alert alert-warning">Cần có bảng <code>shifts</code> và <code>shift_assigns</code> (hoặc <code>shift_assignments</code>) để sử dụng chức năng này.</div>
<?php endif; ?>
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 mb-3">Phân công cho 1 nhân viên</h2>
                <form method="post" class="row g-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="assign_shift">
                    <div class="col-md-6">
                        <label class="form-label">Nhân viên</label>
                        <select name="employee_id" class="form-select" required>
                            <option value="">Chọn nhân viên</option>
                            <?php foreach ($employees as $employee): ?>
                                <option value="<?= e($employee['id']) ?>"><?= e($employee['display_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Ca làm việc</label>
                        <select name="shift_id" class="form-select" required>
                            <option value="">Chọn ca</option>
                            <?php foreach ($shifts as $shift): ?>
                                <option value="<?= e($shift['id']) ?>"><?= e($shift['name']) ?><?= $shift['abbreviation'] ? ' (' . e($shift['abbreviation']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Từ ngày</label>
                        <input type="date" name="date_from" class="form-control" required value="<?= e($filterDateFrom) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Đến ngày</label>
                        <input type="date" name="date_to" class="form-control" required value="<?= e($filterDateTo) ?>">
                    </div>
                    <div class="col-12"><button type="submit" class="btn btn-primary">Lưu phân công</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 mb-3">Phân công hàng loạt</h2>
                <form method="post" class="row g-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="bulk_assign">
                    <div class="col-12">
                        <label class="form-label">Chọn nhiều nhân viên</label>
                        <select name="employee_ids[]" class="form-select" multiple size="6" required>
                            <?php foreach ($employees as $employee): ?>
                                <option value="<?= e($employee['id']) ?>"><?= e($employee['display_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Ca làm việc</label>
                        <select name="shift_id" class="form-select" required>
                            <option value="">Chọn ca</option>
                            <?php foreach ($shifts as $shift): ?>
                                <option value="<?= e($shift['id']) ?>"><?= e($shift['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Từ ngày</label>
                        <input type="date" name="date_from" class="form-control" required value="<?= e($filterDateFrom) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Đến ngày</label>
                        <input type="date" name="date_to" class="form-control" required value="<?= e($filterDateTo) ?>">
                    </div>
                    <div class="col-12"><button type="submit" class="btn btn-outline-primary">Áp dụng hàng loạt</button></div>
                </form>
            </div>
        </div>
    </div>
</div>
<div class="card content-card mb-4 no-print">
    <div class="card-body">
        <h2 class="h5 mb-3">Bộ lọc xem phân công</h2>
        <form class="row g-3 align-items-end" method="get">
            <div class="col-md-4">
                <label class="form-label">Nhân viên</label>
                <select name="employee_id" class="form-select">
                    <option value="0">Tất cả</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= e($employee['id']) ?>" <?= $filterEmployeeId === (int) $employee['id'] ? 'selected' : '' ?>><?= e($employee['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3"><label class="form-label">Từ ngày</label><input type="date" name="date_from" class="form-control" value="<?= e($filterDateFrom) ?>"></div>
            <div class="col-md-3"><label class="form-label">Đến ngày</label><input type="date" name="date_to" class="form-control" value="<?= e($filterDateTo) ?>"></div>
            <div class="col-md-2"><button type="submit" class="btn btn-outline-secondary w-100">Lọc</button></div>
        </form>
    </div>
</div>
<div class="card content-card">
    <div class="card-body">
        <h2 class="h5 mb-3">Phân công hiện có</h2>
        <div class="table-responsive">
            <table class="table align-middle table-hover">
                <thead class="table-light">
                <tr>
                    <th>Ngày</th>
                    <th>Nhân viên</th>
                    <th>Ca làm việc</th>
                    <th>Mã ca</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$assignments): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">Chưa có dữ liệu phân công.</td></tr>
                <?php endif; ?>
                <?php foreach ($assignments as $assignment): ?>
                    <tr>
                        <td><?= e(formatDateVN($assignment['shift_date'])) ?></td>
                        <td><?= e($employeeMap[$assignment['user_id']] ?? 'N/A') ?></td>
                        <td><?= e($assignment['shift_name']) ?></td>
                        <td><span class="badge" style="background:<?= e($assignment['color'] ?: '#6c757d') ?>"><?= e($assignment['abbreviation'] ?: 'CA') ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
