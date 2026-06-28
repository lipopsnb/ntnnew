<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once __DIR__ . '/helpers.php';

requireRole(['director', 'accountant', 'manager']);

$stats = [
    'fixed_assets' => (int) fetchScalarSafe($pdo, "SELECT COUNT(*) FROM assets WHERE group_type = 'fixed_asset'", [], 0),
    'low_stock' => (int) fetchScalarSafe($pdo, "SELECT COUNT(*) FROM assets WHERE group_type = 'consumable' AND qty_current < qty_min", [], 0),
    'vehicle_registration_due' => (int) fetchScalarSafe($pdo, "SELECT COUNT(*) FROM assets WHERE group_type = 'vehicle' AND registration_exp IS NOT NULL AND registration_exp <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)", [], 0),
    'monthly_expense' => (float) fetchScalarSafe(
        $pdo,
        "SELECT COALESCE((SELECT SUM(amount) FROM vehicle_expenses WHERE YEAR(expense_date) = YEAR(CURDATE()) AND MONTH(expense_date) = MONTH(CURDATE())), 0)
                + COALESCE((SELECT SUM(amount_paid) FROM expense_payments WHERE YEAR(payment_date) = YEAR(CURDATE()) AND MONTH(payment_date) = MONTH(CURDATE())), 0)",
        [],
        0
    ),
];

$recentMaintenanceLogs = fetchAllSafe(
    $pdo,
    'SELECT ml.maintenance_date, ml.type, ml.description, ml.cost, ml.performed_by, ml.next_date, a.name AS asset_name
     FROM maintenance_logs ml
     INNER JOIN assets a ON a.id = ml.asset_id
     ORDER BY ml.maintenance_date DESC, ml.id DESC
     LIMIT 5'
);

$pendingExpenseRequests = fetchAllSafe(
    $pdo,
    "SELECT er.id, er.request_code, er.request_date, er.amount_requested, er.purpose, ec.name AS category_name, u.full_name AS requester_name
     FROM expense_requests er
     INNER JOIN expense_categories ec ON ec.id = er.category_id
     INNER JOIN users u ON u.id = er.requester_id
     WHERE er.status = 'submitted'
     ORDER BY er.request_date DESC, er.id DESC
     LIMIT 5"
);

$pageTitle = 'Hành chính';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => 'dashboard.php'],
    ['label' => 'Hành chính'],
];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Dashboard hành chính</h1>
        <p class="text-muted mb-0">Theo dõi nhanh tài sản, vật tư, xe cộ và chi phí nội bộ.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= e(basePath('modules/admin/asset_create.php')) ?>" class="btn btn-primary"><i class="fa-solid fa-plus me-2"></i>Thêm tài sản</a>
        <a href="<?= e(basePath('modules/admin/expense.php')) ?>" class="btn btn-outline-success"><i class="fa-solid fa-file-circle-plus me-2"></i>Tạo đề xuất chi phí</a>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-3 col-md-6"><div class="card stat-card h-100"><div class="card-body"><div class="d-flex justify-content-between align-items-start"><div><div class="text-muted small">Tổng tài sản cố định</div><div class="display-6 fw-bold"><?= number_format($stats['fixed_assets']) ?></div></div><div class="text-primary fs-3"><i class="fa-solid fa-building-circle-check"></i></div></div></div></div></div>
    <div class="col-xl-3 col-md-6"><div class="card stat-card h-100"><div class="card-body"><div class="d-flex justify-content-between align-items-start"><div><div class="text-muted small">Vật tư tồn kho thấp</div><div class="display-6 fw-bold"><?= number_format($stats['low_stock']) ?></div></div><div class="text-danger fs-3"><i class="fa-solid fa-triangle-exclamation"></i></div></div></div></div></div>
    <div class="col-xl-3 col-md-6"><div class="card stat-card h-100"><div class="card-body"><div class="d-flex justify-content-between align-items-start"><div><div class="text-muted small">Xe cần đăng kiểm 30 ngày</div><div class="display-6 fw-bold"><?= number_format($stats['vehicle_registration_due']) ?></div></div><div class="text-warning fs-3"><i class="fa-solid fa-car-side"></i></div></div></div></div></div>
    <div class="col-xl-3 col-md-6"><div class="card stat-card h-100"><div class="card-body"><div class="d-flex justify-content-between align-items-start"><div><div class="text-muted small">Chi phí tháng này</div><div class="h3 fw-bold mb-0"><?= e(formatCurrency($stats['monthly_expense'])) ?></div></div><div class="text-success fs-3"><i class="fa-solid fa-money-bill-trend-up"></i></div></div></div></div></div>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card content-card h-100">
            <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Nhật ký bảo trì gần đây</h2>
                <a href="<?= e(basePath('modules/admin/maintenance.php')) ?>" class="btn btn-sm btn-outline-primary">Xem tất cả</a>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Tài sản</th><th>Ngày bảo trì</th><th>Loại</th><th>Mô tả</th><th>Chi phí</th></tr></thead>
                    <tbody>
                    <?php if ($recentMaintenanceLogs === []): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">Chưa có lịch sử bảo trì.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentMaintenanceLogs as $log): ?>
                            <tr>
                                <td class="fw-semibold"><?= e($log['asset_name'] ?? '—') ?></td>
                                <td><?= e(formatDate($log['maintenance_date'] ?? null)) ?></td>
                                <td><span class="badge text-bg-<?= e(adminMaintenanceTypeBadgeClass((string) ($log['type'] ?? ''))) ?>"><?= e(adminMaintenanceTypeLabel((string) ($log['type'] ?? ''))) ?></span></td>
                                <td><div><?= e($log['description'] ?? '—') ?></div><small class="text-muted">Thực hiện: <?= e($log['performed_by'] ?? '—') ?></small></td>
                                <td><?= e(formatCurrency($log['cost'] ?? 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card content-card h-100">
            <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Đề xuất chi phí chờ duyệt</h2>
                <a href="<?= e(basePath('modules/admin/expense_approve.php')) ?>" class="btn btn-sm btn-outline-success">Xử lý</a>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Mã đề xuất</th><th>Người đề xuất</th><th>Danh mục</th><th>Số tiền</th></tr></thead>
                    <tbody>
                    <?php if ($pendingExpenseRequests === []): ?>
                        <tr><td colspan="4" class="text-center py-4 text-muted">Không có đề xuất đang chờ duyệt.</td></tr>
                    <?php else: ?>
                        <?php foreach ($pendingExpenseRequests as $request): ?>
                            <tr>
                                <td><div class="fw-semibold"><?= e($request['request_code'] ?? '—') ?></div><small class="text-muted"><?= e(formatDate($request['request_date'] ?? null)) ?></small></td>
                                <td><?= e($request['requester_name'] ?? '—') ?></td>
                                <td><div><?= e($request['category_name'] ?? '—') ?></div><small class="text-muted"><?= e($request['purpose'] ?? '—') ?></small></td>
                                <td class="fw-semibold text-success"><?= e(formatCurrency($request['amount_requested'] ?? 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
