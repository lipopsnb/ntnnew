<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';

requireLogin();

$pdo = getDBConnection();
$user = currentUser();
$pageTitle = 'Tổng quan - NTN ERP';
$today = date('Y-m-d');
$badge = getRoleBadge($user['role'] ?? '');

$attendanceStmt = $pdo->prepare('SELECT COUNT(*) FROM attendance_logs WHERE work_date = ?');
$attendanceStmt->execute([$today]);
$todayAttendanceCount = (int) $attendanceStmt->fetchColumn();

$leaveStmt = $pdo->prepare('SELECT COUNT(*) FROM leave_requests WHERE status = ?');
$leaveStmt->execute(['pending']);
$pendingLeaveCount = (int) $leaveStmt->fetchColumn();

$otStmt = $pdo->prepare('SELECT COUNT(*) FROM overtime_requests WHERE status = ?');
$otStmt->execute(['pending']);
$pendingOtCount = (int) $otStmt->fetchColumn();

$totalEmployees = null;
if (hasRole('director', 'accountant', 'manager')) {
    $employeeStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE is_active = ?');
    $employeeStmt->execute([1]);
    $totalEmployees = (int) $employeeStmt->fetchColumn();
}

$warehouseSummary = [];
if (hasRole('production', 'warehouse')) {
    $stockStmt = $pdo->prepare(
        'SELECT pc.product_code, pc.description, ws.qty_pending, ws.qty_completed, ws.qty_defect
         FROM warehouse_stock ws
         INNER JOIN product_codes pc ON pc.id = ws.product_code_id
         ORDER BY pc.product_code ASC
         LIMIT 5'
    );
    $stockStmt->execute();
    $warehouseSummary = $stockStmt->fetchAll(PDO::FETCH_ASSOC);
}

$debtSummary = null;
if (hasRole('director', 'accountant')) {
    $debtStmt = $pdo->prepare('SELECT COALESCE(SUM(remaining_amount), 0) FROM debt_tracking WHERE status != ?');
    $debtStmt->execute(['paid']);
    $debtSummary = (float) $debtStmt->fetchColumn();
}

$notificationStmt = $pdo->prepare('SELECT title, message, created_at FROM notifications WHERE user_id = ? AND is_read = ? ORDER BY created_at DESC LIMIT 5');
$notificationStmt->execute([$user['user_id'], 0]);
$recentNotifications = $notificationStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="container-fluid">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-4 gap-3">
        <div>
            <h1 class="h3 mb-2">Xin chào, <?= e($user['full_name']) ?></h1>
            <span class="badge bg-<?= e($badge['class']) ?>">
                <i class="<?= e($badge['icon']) ?> me-1"></i><?= e($badge['label']) ?>
            </span>
        </div>
        <div class="text-muted">Hôm nay: <?= e(formatDate($today)) ?></div>
    </div>

    <?php showFlash(); ?>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card summary-card h-100">
                <div class="card-body d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted small">Số lượt chấm công hôm nay</div>
                        <div class="display-6 fw-semibold"><?= $todayAttendanceCount ?></div>
                    </div>
                    <div class="icon-wrap bg-primary-subtle text-primary"><i class="fa-regular fa-calendar-check"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card summary-card h-100">
                <div class="card-body d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted small">Đơn nghỉ phép chờ duyệt</div>
                        <div class="display-6 fw-semibold"><?= $pendingLeaveCount ?></div>
                    </div>
                    <div class="icon-wrap bg-warning-subtle text-warning"><i class="fa-solid fa-file-signature"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card summary-card h-100">
                <div class="card-body d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted small">Đơn OT chờ duyệt</div>
                        <div class="display-6 fw-semibold"><?= $pendingOtCount ?></div>
                    </div>
                    <div class="icon-wrap bg-info-subtle text-info"><i class="fa-regular fa-clock"></i></div>
                </div>
            </div>
        </div>

        <?php if ($totalEmployees !== null): ?>
            <div class="col-md-6 col-xl-4">
                <div class="card summary-card h-100">
                    <div class="card-body d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small">Tổng nhân viên đang hoạt động</div>
                            <div class="display-6 fw-semibold"><?= $totalEmployees ?></div>
                        </div>
                        <div class="icon-wrap bg-success-subtle text-success"><i class="fa-solid fa-users"></i></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($debtSummary !== null): ?>
            <div class="col-md-6 col-xl-4">
                <div class="card summary-card h-100">
                    <div class="card-body d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small">Tổng công nợ còn lại</div>
                            <div class="h2 fw-semibold text-danger mb-0"><?= e(formatMoney($debtSummary)) ?> VNĐ</div>
                        </div>
                        <div class="icon-wrap bg-danger-subtle text-danger"><i class="fa-solid fa-money-bill-wave"></i></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="row g-4">
        <?php if (hasRole('production', 'warehouse')): ?>
            <div class="col-xl-7">
                <div class="card widget-card h-100">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h2 class="h5 mb-0">Tóm tắt tồn kho</h2>
                    </div>
                    <div class="card-body pt-3">
                        <div class="production-table">
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Mã SP</th>
                                            <th>Mô tả</th>
                                            <th class="text-end">Chờ SX</th>
                                            <th class="text-end">Hoàn thành</th>
                                            <th class="text-end">Lỗi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($warehouseSummary): ?>
                                            <?php foreach ($warehouseSummary as $stock): ?>
                                                <tr>
                                                    <td class="fw-semibold"><?= e($stock['product_code']) ?></td>
                                                    <td><?= e($stock['description']) ?></td>
                                                    <td class="text-end"><?= e(formatMoney($stock['qty_pending'])) ?></td>
                                                    <td class="text-end"><?= e(formatMoney($stock['qty_completed'])) ?></td>
                                                    <td class="text-end text-danger"><?= e(formatMoney($stock['qty_defect'])) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="5" class="text-center text-muted py-4">Chưa có dữ liệu tồn kho.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="<?= hasRole('production', 'warehouse') ? 'col-xl-5' : 'col-12' ?>">
            <div class="card widget-card h-100">
                <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                    <h2 class="h5 mb-0">Thông báo chưa đọc</h2>
                    <span class="badge bg-primary"><?= count($recentNotifications) ?></span>
                </div>
                <div class="card-body pt-3">
                    <?php if ($recentNotifications): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($recentNotifications as $notification): ?>
                                <div class="list-group-item px-0">
                                    <div class="fw-semibold"><?= e($notification['title']) ?></div>
                                    <div class="text-muted small"><?= e($notification['message']) ?></div>
                                    <div class="text-muted x-small mt-1"><?= e(formatDateTime($notification['created_at'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-muted">Không có thông báo chưa đọc.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
