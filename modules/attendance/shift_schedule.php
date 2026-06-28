<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once __DIR__ . '/helpers.php';

requireRole(['director', 'accountant', 'manager', 'production']);

$pageTitle = 'Lịch ca tháng';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => '/ntn_erp/index.php'],
    ['label' => 'Lịch ca tháng'],
];
$month = max(1, min(12, (int) ($_GET['month'] ?? date('n'))));
$year = max(2020, min(2100, (int) ($_GET['year'] ?? date('Y'))));
$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEnd = date('Y-m-t', strtotime($monthStart));
$daysInMonth = (int) date('t', strtotime($monthStart));
$employees = fetchEmployees($pdo);
$assignments = fetchShiftAssignments($pdo, $monthStart, $monthEnd);
$matrix = [];
foreach ($assignments as $assignment) {
    $matrix[$assignment['user_id']][$assignment['shift_date']] = $assignment;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Lịch ca tháng</h1>
        <p class="text-muted mb-0">Dạng lưới theo nhân viên và ngày trong tháng, tối ưu để in.</p>
    </div>
    <button class="btn btn-outline-secondary no-print" onclick="window.print()"><i class="fa-solid fa-print me-2"></i>In lịch ca</button>
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
                <button class="btn btn-primary w-100" type="submit">Xem lịch</button>
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
                </tr>
                </thead>
                <tbody>
                <?php if (!$employees): ?>
                    <tr><td colspan="<?= $daysInMonth + 1 ?>" class="text-center text-muted py-4">Chưa có dữ liệu nhân viên.</td></tr>
                <?php endif; ?>
                <?php foreach ($employees as $employee): ?>
                    <tr>
                        <td class="text-start fw-semibold"><?= e($employee['display_name']) ?></td>
                        <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                            <?php $date = sprintf('%04d-%02d-%02d', $year, $month, $day); ?>
                            <?php $assignment = $matrix[$employee['id']][$date] ?? null; ?>
                            <td style="background:<?= e($assignment['color'] ?? '#f8fafc') ?>; color:#111827; min-width:42px;">
                                <?= e($assignment['abbreviation'] ?? '') ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
