<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once __DIR__ . '/helpers.php';

requireRole(['director', 'accountant']);

$pageTitle = 'Quản lý bảng lương';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => '/ntn_erp/index.php'],
    ['label' => 'Quản lý bảng lương'],
];
$periodReady = tableExists($pdo, 'payroll_periods');
$itemReady = getPayrollSlipTable($pdo) !== null;
$viewId = (int) ($_GET['view'] ?? 0);
$exportId = (int) ($_GET['export'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $periodReady) {
    validateCsrfOrAbort();
    $cols = payrollPeriodColumns($pdo);
    $action = $_POST['action'] ?? '';

    if ($action === 'create_period') {
        $month = max(1, min(12, (int) ($_POST['month'] ?? date('n'))));
        $year = max(2020, min(2100, (int) ($_POST['year'] ?? date('Y'))));
        $workingDays = (int) ($_POST['working_days'] ?? 26);
        $stmt = $pdo->prepare(sprintf('INSERT INTO payroll_periods (`%s`, `%s`, `%s`, `%s`) VALUES (?, ?, ?, ?)', $cols['month'], $cols['year'], $cols['working_days'], $cols['status']));
        $stmt->execute([$month, $year, $workingDays, 'draft']);
        $periodId = (int) $pdo->lastInsertId();
        if (columnExists($pdo, 'payroll_periods', $cols['created_by'])) {
            $update = $pdo->prepare(sprintf('UPDATE payroll_periods SET `%s` = ? WHERE `%s` = ?', $cols['created_by'], $cols['id']));
            $update->execute([currentUserId(), $periodId]);
        }
        setFlashMessage('success', 'Đã tạo kỳ lương mới.');
    }

    if ($action === 'approve_period') {
        $periodId = (int) ($_POST['period_id'] ?? 0);
        $stmt = $pdo->prepare(sprintf('UPDATE payroll_periods SET `%s` = ? WHERE `%s` = ?', $cols['status'], $cols['id']));
        $stmt->execute(['approved', $periodId]);
        setFlashMessage('success', 'Đã duyệt kỳ lương.');
    }

    redirect('/ntn_erp/modules/payroll/index.php');
}

$periods = fetchPayrollPeriods($pdo);
$detailRows = [];
$detailPeriod = null;
if (($viewId || $exportId) && $periodReady) {
    $selectedId = $viewId ?: $exportId;
    foreach ($periods as $period) {
        if ((int) $period['id'] === $selectedId) {
            $detailPeriod = $period;
            break;
        }
    }
    if ($detailPeriod && $itemReady) {
        $itemCols = payrollItemColumns($pdo);
        $userTable = getEmployeeSourceTable($pdo);
        $userNameColumn = $userTable ? (pickColumn($pdo, $userTable, ['full_name', 'name', 'employee_name', 'username']) ?? 'id') : null;
        $sql = sprintf(
            'SELECT pi.`%s` AS id, pi.`%s` AS user_id, pi.`%s` AS basic_salary, pi.`%s` AS working_days, pi.`%s` AS ot_hours, pi.`%s` AS ot_amount, pi.`%s` AS allowances, pi.`%s` AS deductions, pi.`%s` AS net_salary, %s AS employee_name
             FROM `%s` pi %s
             WHERE pi.`%s` = ?
             ORDER BY %s',
            $itemCols['id'], $itemCols['user'], $itemCols['basic_salary'], $itemCols['working_days'], $itemCols['ot_hours'], $itemCols['ot_amount'], $itemCols['allowances'], $itemCols['deductions'], $itemCols['net_salary'],
            $userTable && $userNameColumn ? 'u.`' . $userNameColumn . '`' : 'NULL',
            $itemCols['table'],
            $userTable && $userNameColumn ? 'LEFT JOIN `' . $userTable . '` u ON u.id = pi.`' . $itemCols['user'] . '`' : '',
            $itemCols['period'],
            $userTable && $userNameColumn ? 'u.`' . $userNameColumn . '`' : 'pi.`' . $itemCols['id'] . '`'
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$selectedId]);
        $detailRows = $stmt->fetchAll();
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Quản lý bảng lương</h1>
        <p class="text-muted mb-0">Quản lý kỳ lương, theo dõi trạng thái và xem chi tiết phiếu lương theo kỳ.</p>
    </div>
    <button class="btn btn-primary no-print" data-bs-toggle="modal" data-bs-target="#createPayrollModal">
        <i class="fa-solid fa-plus me-2"></i>Tạo bảng lương
    </button>
</div>
<?php if (!$periodReady): ?>
    <div class="alert alert-warning">Chưa tìm thấy bảng <code>payroll_periods</code>. Vui lòng tạo schema trước khi sử dụng.</div>
<?php endif; ?>
<div class="card content-card mb-4">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table align-middle table-hover">
                <thead class="table-light">
                <tr>
                    <th>Tháng/Năm</th>
                    <th>Ngày công</th>
                    <th>Trạng thái</th>
                    <th>Người tạo</th>
                    <th>Ngày tạo</th>
                    <th class="no-print">Thao tác</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$periods): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Chưa có kỳ lương nào.</td></tr>
                <?php endif; ?>
                <?php foreach ($periods as $period): ?>
                    <tr>
                        <td class="fw-semibold">Tháng <?= e($period['period_month']) ?>/<?= e($period['period_year']) ?></td>
                        <td><?= e($period['working_days']) ?></td>
                        <td><span class="badge bg-<?= e(payrollStatusBadge($period['status'])) ?>"><?= e(ucfirst($period['status'])) ?></span></td>
                        <td><?= e($period['created_by_name'] ?: $period['created_by'] ?: '-') ?></td>
                        <td><?= e(formatDateTimeVN($period['created_at'])) ?></td>
                        <td class="no-print">
                            <div class="d-flex flex-wrap gap-2">
                                <a class="btn btn-sm btn-outline-primary" href="?view=<?= e($period['id']) ?>">Xem chi tiết</a>
                                <?php if (in_array($period['status'], ['draft', 'submitted'], true)): ?>
                                    <form method="post">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="approve_period">
                                        <input type="hidden" name="period_id" value="<?= e($period['id']) ?>">
                                        <button class="btn btn-sm btn-success" type="submit">Duyệt</button>
                                    </form>
                                <?php endif; ?>
                                <a class="btn btn-sm btn-outline-secondary" href="?export=<?= e($period['id']) ?>">Xuất PDF</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php if ($detailPeriod): ?>
    <div class="card content-card<?= $exportId ? ' border-primary' : '' ?>">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h5 mb-0">Chi tiết kỳ lương tháng <?= e($detailPeriod['period_month']) ?>/<?= e($detailPeriod['period_year']) ?></h2>
                <?php if ($exportId): ?><script>window.addEventListener('load', () => window.print());</script><?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table align-middle table-bordered">
                    <thead class="table-light">
                    <tr>
                        <th>Nhân viên</th>
                        <th>Lương cơ bản</th>
                        <th>Ngày công</th>
                        <th>OT (giờ)</th>
                        <th>Tiền OT</th>
                        <th>Phụ cấp</th>
                        <th>Khấu trừ</th>
                        <th>Thực lãnh</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$detailRows): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">Chưa có chi tiết lương cho kỳ này.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($detailRows as $row): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($row['employee_name'] ?: 'N/A') ?></td>
                            <td><?= e(number_format((float) $row['basic_salary'], 0, ',', '.')) ?></td>
                            <td><?= e($row['working_days']) ?></td>
                            <td><?= e(number_format((float) $row['ot_hours'], 2)) ?></td>
                            <td><?= e(number_format((float) $row['ot_amount'], 0, ',', '.')) ?></td>
                            <td><?= e(number_format((float) $row['allowances'], 0, ',', '.')) ?></td>
                            <td><?= e(number_format((float) $row['deductions'], 0, ',', '.')) ?></td>
                            <td class="fw-bold text-primary"><?= e(number_format((float) $row['net_salary'], 0, ',', '.')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
<div class="modal fade" id="createPayrollModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h2 class="modal-title fs-5">Tạo bảng lương</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create_period">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Tháng</label>
                            <select name="month" class="form-select">
                                <?php foreach (monthOptions() as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= $value === (int) date('n') ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Năm</label>
                            <select name="year" class="form-select">
                                <?php foreach (buildYearOptions() as $optionYear): ?>
                                    <option value="<?= $optionYear ?>" <?= $optionYear === (int) date('Y') ? 'selected' : '' ?>><?= $optionYear ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ngày công chuẩn</label>
                            <input type="number" name="working_days" class="form-control" value="26" min="1" max="31">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Đóng</button>
                    <button type="submit" class="btn btn-primary">Tạo kỳ lương</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
