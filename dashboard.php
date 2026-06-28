<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';

requireLogin();

$pageTitle = 'Tổng quan';
$breadcrumbs = [
    ['label' => 'Tổng quan'],
];

$stats = [
    'active_users' => (int) fetchScalarSafe($pdo, 'SELECT COUNT(*) FROM users WHERE is_active = :is_active', ['is_active' => 1], 0),
    'job_orders_in_progress' => (int) fetchScalarSafe($pdo, 'SELECT COUNT(*) FROM job_orders WHERE status = :status', ['status' => 'in_progress'], 0),
    'open_invoices' => (int) fetchScalarSafe($pdo, "SELECT COUNT(*) FROM invoices WHERE status IN ('unpaid', 'partial')", [], 0),
    'assets_due' => (int) fetchScalarSafe($pdo, 'SELECT COUNT(*) FROM assets WHERE next_maintenance <= DATE_ADD(NOW(), INTERVAL 7 DAY)', [], 0),
];

$financialSummary = null;
if (in_array($_SESSION['role'], ['director', 'accountant'], true)) {
    $financialSummary = fetchOneSafe(
        $pdo,
        "SELECT
            COALESCE(SUM(CASE WHEN status IN ('unpaid', 'partial') THEN total_amount - paid_amount ELSE 0 END), 0) AS total_debt,
            COALESCE(SUM(CASE WHEN MONTH(invoice_date) = MONTH(CURDATE()) AND YEAR(invoice_date) = YEAR(CURDATE()) THEN total_amount ELSE 0 END), 0) AS monthly_revenue
         FROM invoices",
        [],
        ['total_debt' => 0, 'monthly_revenue' => 0]
    );
}

$showTodayJobs = in_array($_SESSION['role'], ['production', 'warehouse'], true);
$todayJobOrders = [];
if ($showTodayJobs) {
    $todayJobOrders = fetchAllSafe(
        $pdo,
        'SELECT jo.job_code AS code, c.name AS customer_name, jo.status, COALESCE(SUM(joi.qty_received), 0) AS quantity, jo.created_at
         FROM job_orders jo
         LEFT JOIN customers c ON c.id = jo.customer_id
         LEFT JOIN job_order_items joi ON joi.job_order_id = jo.id
         WHERE jo.received_date = CURDATE() OR DATE(jo.created_at) = CURDATE()
         GROUP BY jo.id, jo.job_code, c.name, jo.status, jo.created_at
         ORDER BY jo.created_at DESC
         LIMIT 10'
    );
}

$myAttendance = null;
$leaveBalance = null;
if ($_SESSION['role'] === 'employee') {
    $myAttendance = fetchOneSafe(
        $pdo,
        'SELECT check_in, check_out, status
         FROM attendance_logs
         WHERE user_id = :user_id AND work_date = CURDATE()
         LIMIT 1',
        ['user_id' => (int) $_SESSION['user_id']],
        ['check_in' => null, 'check_out' => null, 'status' => 'Chưa chấm công']
    );
    $leaveBalance = getLeaveBalance($pdo, (int) $_SESSION['user_id']);
}

$quickActions = [
    'director' => [
        ['label' => 'Quản lý nhân viên', 'icon' => 'fa-users', 'url' => 'modules/users/index.php', 'class' => 'primary'],
        ['label' => 'Tổng quan tài chính', 'icon' => 'fa-coins', 'url' => 'dashboard.php#finance', 'class' => 'success'],
    ],
    'accountant' => [
        ['label' => 'Tạo nhân viên mới', 'icon' => 'fa-user-plus', 'url' => 'modules/users/create.php', 'class' => 'primary'],
        ['label' => 'Xem công nợ', 'icon' => 'fa-file-invoice-dollar', 'url' => 'dashboard.php#finance', 'class' => 'success'],
    ],
    'manager' => [
        ['label' => 'Danh sách nhân viên', 'icon' => 'fa-address-book', 'url' => 'modules/users/index.php', 'class' => 'primary'],
        ['label' => 'Thêm nhân viên', 'icon' => 'fa-user-plus', 'url' => 'modules/users/create.php', 'class' => 'warning'],
    ],
    'production' => [
        ['label' => 'Lệnh sản xuất hôm nay', 'icon' => 'fa-industry', 'url' => 'dashboard.php#today-jobs', 'class' => 'primary'],
    ],
    'warehouse' => [
        ['label' => 'Đơn hàng kho hôm nay', 'icon' => 'fa-warehouse', 'url' => 'dashboard.php#today-jobs', 'class' => 'info'],
    ],
    'employee' => [
        ['label' => 'Hồ sơ cá nhân', 'icon' => 'fa-id-card', 'url' => 'modules/users/profile.php', 'class' => 'primary'],
        ['label' => 'Đổi mật khẩu', 'icon' => 'fa-key', 'url' => 'modules/users/change_password.php', 'class' => 'secondary'],
    ],
];

$recentJobOrders = fetchAllSafe(
    $pdo,
    'SELECT jo.job_code AS code, c.name AS customer_name, jo.status, jo.created_at
     FROM job_orders jo
     LEFT JOIN customers c ON c.id = jo.customer_id
     ORDER BY jo.created_at DESC
     LIMIT 5'
);
$recentLeaveRequests = fetchAllSafe(
    $pdo,
    'SELECT u.full_name, lt.name AS leave_type, lr.date_from, lr.date_to, lr.status, lr.approved_at
     FROM leave_requests lr
     LEFT JOIN users u ON u.id = lr.user_id
     LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
     ORDER BY lr.id DESC
     LIMIT 5'
);

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <div>
        <h1 class="h3 mb-1">Xin chào, <?= e($_SESSION['full_name']) ?></h1>
        <p class="text-muted mb-0">Theo dõi nhanh tình hình vận hành của hệ thống NTN ERP.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php foreach ($quickActions[$_SESSION['role']] ?? [] as $action): ?>
            <a href="<?= e(basePath($action['url'])) ?>" class="btn btn-<?= e($action['class']) ?>">
                <i class="fa-solid <?= e($action['icon']) ?> me-2"></i><?= e($action['label']) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-3 col-md-6"><div class="card stat-card h-100"><div class="card-body"><div class="d-flex justify-content-between align-items-start"><div><div class="text-muted small">Nhân viên đang làm việc</div><div class="display-6 fw-bold"><?= number_format($stats['active_users']) ?></div></div><div class="text-primary fs-3"><i class="fa-solid fa-users"></i></div></div></div></div></div>
    <div class="col-xl-3 col-md-6"><div class="card stat-card h-100"><div class="card-body"><div class="d-flex justify-content-between align-items-start"><div><div class="text-muted small">Phiếu GC đang xử lý</div><div class="display-6 fw-bold"><?= number_format($stats['job_orders_in_progress']) ?></div></div><div class="text-warning fs-3"><i class="fa-solid fa-gears"></i></div></div></div></div></div>
    <div class="col-xl-3 col-md-6"><div class="card stat-card h-100"><div class="card-body"><div class="d-flex justify-content-between align-items-start"><div><div class="text-muted small">Hóa đơn chưa thanh toán</div><div class="display-6 fw-bold"><?= number_format($stats['open_invoices']) ?></div></div><div class="text-danger fs-3"><i class="fa-solid fa-file-invoice-dollar"></i></div></div></div></div></div>
    <div class="col-xl-3 col-md-6"><div class="card stat-card h-100"><div class="card-body"><div class="d-flex justify-content-between align-items-start"><div><div class="text-muted small">Tài sản cần bảo trì</div><div class="display-6 fw-bold"><?= number_format($stats['assets_due']) ?></div></div><div class="text-info fs-3"><i class="fa-solid fa-screwdriver-wrench"></i></div></div></div></div></div>
</div>

<div class="row g-4">
    <?php if ($financialSummary !== null): ?>
        <div class="col-12" id="finance"><div class="card content-card"><div class="card-header bg-white border-0 pt-4 px-4"><h2 class="h5 mb-0">Tổng hợp tài chính</h2></div><div class="card-body px-4 pb-4"><div class="row g-3"><div class="col-md-6"><div class="rounded-4 bg-danger-subtle p-4 h-100"><div class="text-danger-emphasis small mb-2">Tổng công nợ</div><div class="h3 mb-0 fw-bold"><?= e(formatCurrency($financialSummary['total_debt'] ?? 0)) ?></div></div></div><div class="col-md-6"><div class="rounded-4 bg-success-subtle p-4 h-100"><div class="text-success-emphasis small mb-2">Doanh thu tháng</div><div class="h3 mb-0 fw-bold"><?= e(formatCurrency($financialSummary['monthly_revenue'] ?? 0)) ?></div></div></div></div></div></div></div>
    <?php endif; ?>

    <?php if ($showTodayJobs): ?>
        <div class="col-12" id="today-jobs"><div class="card content-card"><div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center"><h2 class="h5 mb-0">Lệnh sản xuất / kho hôm nay</h2><span class="badge text-bg-light"><?= count($todayJobOrders) ?> mục</span></div><div class="table-responsive"><table class="table align-middle mb-0"><thead class="table-light"><tr><th>Mã phiếu</th><th>Khách hàng</th><th>Số lượng nhận</th><th>Trạng thái</th><th>Thời gian tạo</th></tr></thead><tbody><?php if ($todayJobOrders === []): ?><tr><td colspan="5" class="text-center py-4 text-muted">Chưa có lệnh nào trong hôm nay.</td></tr><?php else: ?><?php foreach ($todayJobOrders as $jobOrder): ?><tr><td class="fw-semibold"><?= e($jobOrder['code'] ?? '—') ?></td><td><?= e($jobOrder['customer_name'] ?? '—') ?></td><td><?= number_format((float) ($jobOrder['quantity'] ?? 0), 0, ',', '.') ?></td><td><span class="badge text-bg-warning"><?= e((string) ($jobOrder['status'] ?? '—')) ?></span></td><td><?= e(formatDateTime($jobOrder['created_at'] ?? null)) ?></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div></div>
    <?php endif; ?>

    <?php if ($_SESSION['role'] === 'employee'): ?>
        <div class="col-lg-6"><div class="card content-card h-100"><div class="card-header bg-white border-0 pt-4 px-4"><h2 class="h5 mb-0">Chấm công hôm nay</h2></div><div class="card-body px-4 pb-4"><div class="mb-2"><strong>Check-in:</strong> <?= e(formatDateTime($myAttendance['check_in'] ?? null, 'H:i d/m/Y')) ?></div><div class="mb-2"><strong>Check-out:</strong> <?= e(formatDateTime($myAttendance['check_out'] ?? null, 'H:i d/m/Y')) ?></div><div><strong>Trạng thái:</strong> <?= e((string) ($myAttendance['status'] ?? 'Chưa chấm công')) ?></div></div></div></div>
        <div class="col-lg-6"><div class="card content-card h-100"><div class="card-header bg-white border-0 pt-4 px-4"><h2 class="h5 mb-0">Số ngày phép còn lại</h2></div><div class="card-body px-4 pb-4 d-flex align-items-center justify-content-between"><div><div class="display-5 fw-bold text-primary"><?= number_format((float) $leaveBalance, 1, ',', '.') ?></div><div class="text-muted">Ngày phép năm còn lại</div></div><i class="fa-solid fa-umbrella-beach text-primary opacity-50" style="font-size: 3rem;"></i></div></div></div>
    <?php endif; ?>

    <div class="col-xl-6"><div class="card content-card h-100"><div class="card-header bg-white border-0 pt-4 px-4"><h2 class="h5 mb-0">Hoạt động phiếu GC gần đây</h2></div><div class="list-group list-group-flush"><?php if ($recentJobOrders === []): ?><div class="list-group-item text-muted">Chưa có dữ liệu.</div><?php else: ?><?php foreach ($recentJobOrders as $jobOrder): ?><div class="list-group-item d-flex justify-content-between align-items-start"><div><div class="fw-semibold"><?= e(($jobOrder['code'] ?? '') . ' - ' . ($jobOrder['customer_name'] ?? '')) ?></div><small class="text-muted"><?= e(formatDateTime($jobOrder['created_at'] ?? null)) ?></small></div><span class="badge text-bg-light"><?= e((string) ($jobOrder['status'] ?? '—')) ?></span></div><?php endforeach; ?><?php endif; ?></div></div></div>
    <div class="col-xl-6"><div class="card content-card h-100"><div class="card-header bg-white border-0 pt-4 px-4"><h2 class="h5 mb-0">Đơn nghỉ phép gần đây</h2></div><div class="list-group list-group-flush"><?php if ($recentLeaveRequests === []): ?><div class="list-group-item text-muted">Chưa có dữ liệu.</div><?php else: ?><?php foreach ($recentLeaveRequests as $leave): ?><div class="list-group-item d-flex justify-content-between align-items-start"><div><div class="fw-semibold"><?= e($leave['full_name'] ?? 'Nhân viên') ?></div><small class="text-muted"><?= e($leave['leave_type'] ?? 'Nghỉ phép') ?> · Từ <?= e(formatDate($leave['date_from'] ?? null)) ?> đến <?= e(formatDate($leave['date_to'] ?? null)) ?></small></div><span class="badge text-bg-light"><?= e((string) ($leave['status'] ?? '—')) ?></span></div><?php endforeach; ?><?php endif; ?></div></div></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
