<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once __DIR__ . '/helpers.php';

requireRole(['director', 'accountant', 'manager', 'production']);

$pageTitle = 'Bảng chấm công tổng hợp';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => '/ntn_erp/index.php'],
    ['label' => 'Bảng chấm công tổng hợp'],
];

$month = max(1, min(12, (int) ($_GET['month'] ?? date('n'))));
$year = max(2020, min(2100, (int) ($_GET['year'] ?? date('Y'))));
$departmentId = $_GET['department_id'] ?? '';
$employeeId = $_GET['employee_id'] ?? '';
$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEnd = date('Y-m-t', strtotime($monthStart));
$employees = fetchEmployees($pdo, ['department_id' => $departmentId ?: null, 'employee_id' => $employeeId ?: null]);
$allEmployees = fetchEmployees($pdo);
$departments = fetchDepartments($pdo);
$userIds = array_column($employees, 'id');
$logsMap = getAttendanceLogMap($pdo, $userIds, $monthStart, $monthEnd);
$leaveMap = getApprovedLeaveMap($pdo, $userIds, $monthStart, $monthEnd);
$holidayMap = getHolidayMap($pdo, $monthStart, $monthEnd);
$daysInMonth = (int) date('t', strtotime($monthStart));
$reportRows = [];

foreach ($employees as $employee) {
    $totals = ['work' => 0, 'late' => 0, 'absent' => 0];
    $cells = [];
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $state = inferAttendanceStatus($date, $logsMap[$employee['id']][$date] ?? null, $leaveMap[$employee['id']][$date] ?? null, $holidayMap[$date] ?? null);
        $meta = attendanceStatusMeta($state['status']);
        $cells[$day] = ['status' => $state['status'], 'label' => $meta['label'], 'class' => $meta['cell']];
        if (in_array($state['status'], ['present', 'late', 'leave'], true)) {
            $totals['work']++;
        }
        if ($state['status'] === 'late') {
            $totals['late']++;
        }
        if ($state['status'] === 'absent') {
            $totals['absent']++;
        }
    }
    $reportRows[] = ['employee' => $employee, 'cells' => $cells, 'totals' => $totals];
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Bảng chấm công tổng hợp</h1>
        <p class="text-muted mb-0">Theo dõi công, đi trễ và vắng theo từng ngày trong tháng.</p>
    </div>
    <button class="btn btn-outline-secondary no-print" onclick="window.print()"><i class="fa-solid fa-print me-2"></i>In / Xuất</button>
</div>

<div class="card content-card mb-4 no-print">
    <div class="card-body">
        <form class="row g-3 align-items-end" method="get">
            <div class="col-md-2">
                <label class="form-label">Tháng</label>
                <select name="month" class="form-select">
                    <?php foreach (monthOptions() as $value => $label): ?>
                        <option value="<?= $value ?>" <?= $value === $month ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Năm</label>
                <select name="year" class="form-select">
                    <?php foreach (buildYearOptions() as $optionYear): ?>
                        <option value="<?= $optionYear ?>" <?= $optionYear === $year ? 'selected' : '' ?>><?= $optionYear ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Phòng ban</label>
                <select name="department_id" class="form-select">
                    <option value="">Tất cả</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e($department['id']) ?>" <?= (string) $departmentId === (string) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Nhân viên</label>
                <select name="employee_id" class="form-select">
                    <option value="">Tất cả</option>
                    <?php foreach ($allEmployees as $employee): ?>
                        <option value="<?= e($employee['id']) ?>" <?= (string) $employeeId === (string) $employee['id'] ? 'selected' : '' ?>><?= e($employee['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Lọc dữ liệu</button>
            </div>
        </form>
    </div>
</div>

<div class="card content-card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered align-middle text-center small">
                <thead class="table-light">
                <tr>
                    <th class="text-start">Nhân viên</th>
                    <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                        <th><?= $day ?></th>
                    <?php endfor; ?>
                    <th>Tổng công</th>
                    <th>Đi trễ</th>
                    <th>Vắng</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$reportRows): ?>
                    <tr><td colspan="<?= $daysInMonth + 4 ?>" class="text-center text-muted py-4">Không có dữ liệu nhân viên phù hợp.</td></tr>
                <?php endif; ?>
                <?php foreach ($reportRows as $row): ?>
                    <tr>
                        <td class="text-start fw-semibold"><?= e($row['employee']['display_name']) ?></td>
                        <?php foreach ($row['cells'] as $day => $cell): ?>
                            <td class="<?= e($cell['class']) ?>" title="<?= e($cell['label']) ?>"><?= e(mb_substr($cell['label'], 0, 1)) ?></td>
                        <?php endforeach; ?>
                        <td class="fw-semibold"><?= e($row['totals']['work']) ?></td>
                        <td class="text-warning fw-semibold"><?= e($row['totals']['late']) ?></td>
                        <td class="text-danger fw-semibold"><?= e($row['totals']['absent']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
