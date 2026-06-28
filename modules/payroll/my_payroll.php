<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once __DIR__ . '/helpers.php';

requireLogin();

$pageTitle = 'Phiếu lương cá nhân';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => '/ntn_erp/index.php'],
    ['label' => 'Phiếu lương cá nhân'],
];
$itemReady = getPayrollSlipTable($pdo) !== null && tableExists($pdo, 'payroll_periods');
$itemId = (int) ($_GET['item'] ?? 0);
$userId = currentUserId();
$slips = [];
$detail = null;

if ($itemReady) {
    $itemCols = payrollItemColumns($pdo);
    $periodCols = payrollPeriodColumns($pdo);
    $sql = sprintf(
        'SELECT pi.`%s` AS id, pp.`%s` AS period_month, pp.`%s` AS period_year, pp.`%s` AS period_status,
                pi.`%s` AS basic_salary, pi.`%s` AS working_days, pi.`%s` AS ot_hours, pi.`%s` AS ot_amount,
                pi.`%s` AS allowances, pi.`%s` AS deductions, pi.`%s` AS net_salary
         FROM `%s` pi
         INNER JOIN payroll_periods pp ON pp.`%s` = pi.`%s`
         WHERE pi.`%s` = ?
         ORDER BY pp.`%s` DESC, pp.`%s` DESC',
        $itemCols['id'], $periodCols['month'], $periodCols['year'], $periodCols['status'],
        $itemCols['basic_salary'], $itemCols['working_days'], $itemCols['ot_hours'], $itemCols['ot_amount'],
        $itemCols['allowances'], $itemCols['deductions'], $itemCols['net_salary'],
        $itemCols['table'], $periodCols['id'], $itemCols['period'], $itemCols['user'], $periodCols['year'], $periodCols['month']
    );
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $slips = $stmt->fetchAll();

    foreach ($slips as $slip) {
        if ((int) $slip['id'] === $itemId) {
            $detail = $slip;
            break;
        }
    }
    if ($detail === null && $slips) {
        $detail = $slips[0];
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="mb-4">
    <h1 class="h3 mb-1">Phiếu lương cá nhân</h1>
    <p class="text-muted mb-0">Tra cứu phiếu lương theo kỳ, xem chi tiết và in nhanh khi cần.</p>
</div>
<?php if (!$itemReady): ?>
    <div class="alert alert-warning">Chưa tìm thấy bảng <code>payroll_slips</code> (hoặc <code>payroll_items</code>) và <code>payroll_periods</code>.</div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 mb-3">Danh sách phiếu lương</h2>
                <div class="list-group list-group-flush">
                    <?php if (!$slips): ?>
                        <div class="text-center text-muted py-4">Chưa có phiếu lương.</div>
                    <?php endif; ?>
                    <?php foreach ($slips as $slip): ?>
                        <a class="list-group-item list-group-item-action border rounded-3 mb-2 <?= $detail && (int) $detail['id'] === (int) $slip['id'] ? 'active' : '' ?>" href="?item=<?= e($slip['id']) ?>">
                            <div class="d-flex justify-content-between">
                                <span class="fw-semibold">Tháng <?= e($slip['period_month']) ?>/<?= e($slip['period_year']) ?></span>
                                <span class="badge bg-<?= e(payrollStatusBadge($slip['period_status'])) ?>"><?= e(ucfirst($slip['period_status'])) ?></span>
                            </div>
                            <div class="small mt-1">Thực lãnh: <?= e(number_format((float) $slip['net_salary'], 0, ',', '.')) ?> đ</div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card content-card h-100">
            <div class="card-body">
                <?php if ($detail): ?>
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2 class="h5 mb-0">Chi tiết kỳ lương tháng <?= e($detail['period_month']) ?>/<?= e($detail['period_year']) ?></h2>
                        <button class="btn btn-outline-secondary no-print" onclick="window.print()"><i class="fa-solid fa-print me-2"></i>In</button>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6"><div class="border rounded-3 p-3"><div class="text-muted small">Lương cơ bản</div><div class="fw-bold fs-5"><?= e(number_format((float) $detail['basic_salary'], 0, ',', '.')) ?> đ</div></div></div>
                        <div class="col-md-6"><div class="border rounded-3 p-3"><div class="text-muted small">Ngày công</div><div class="fw-bold fs-5"><?= e($detail['working_days']) ?></div></div></div>
                        <div class="col-md-6"><div class="border rounded-3 p-3"><div class="text-muted small">OT (giờ / tiền)</div><div class="fw-bold fs-5"><?= e(number_format((float) $detail['ot_hours'], 2)) ?> / <?= e(number_format((float) $detail['ot_amount'], 0, ',', '.')) ?> đ</div></div></div>
                        <div class="col-md-6"><div class="border rounded-3 p-3"><div class="text-muted small">Phụ cấp</div><div class="fw-bold fs-5"><?= e(number_format((float) $detail['allowances'], 0, ',', '.')) ?> đ</div></div></div>
                        <div class="col-md-6"><div class="border rounded-3 p-3"><div class="text-muted small">Khấu trừ</div><div class="fw-bold fs-5 text-danger"><?= e(number_format((float) $detail['deductions'], 0, ',', '.')) ?> đ</div></div></div>
                        <div class="col-md-6"><div class="border rounded-3 p-3 bg-primary-subtle"><div class="text-muted small">Thực lãnh</div><div class="fw-bold fs-4 text-primary"><?= e(number_format((float) $detail['net_salary'], 0, ',', '.')) ?> đ</div></div></div>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-5">Chọn một phiếu lương để xem chi tiết.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
