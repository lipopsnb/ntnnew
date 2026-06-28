<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

requireRole(['director', 'manager', 'production', 'warehouse']);
$pdo = erp_db();

$today = date('Y-m-d');
$monthStart = date('Y-m-01');

$stats = [
    'processing' => 0,
    'receipts_today' => 0,
    'outputs_today' => 0,
    'deliveries_today' => 0,
];

$stats['processing'] = (int) $pdo->query("SELECT COUNT(*) FROM job_orders WHERE status = 'in_progress'")->fetchColumn();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM warehouse_receipts WHERE receipt_date = ?');
$stmt->execute([$today]);
$stats['receipts_today'] = (int) $stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM warehouse_outputs WHERE output_date = ?');
$stmt->execute([$today]);
$stats['outputs_today'] = (int) $stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM deliveries WHERE delivery_date = ?');
$stmt->execute([$today]);
$stats['deliveries_today'] = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT status, COUNT(*) AS total FROM job_orders WHERE received_date >= ? AND received_date <= ? GROUP BY status ORDER BY total DESC");
$stmt->execute([$monthStart, date('Y-m-t')]);
$statusRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalMonthOrders = array_sum(array_map(static fn(array $row): int => (int) $row['total'], $statusRows));

$stmt = $pdo->query("SELECT jo.id, jo.job_code, jo.received_date, jo.due_date, jo.status, c.name AS customer_name,
    (SELECT COUNT(*) FROM job_order_items joi WHERE joi.job_order_id = jo.id) AS items_count
    FROM job_orders jo
    INNER JOIN customers c ON c.id = jo.customer_id
    ORDER BY jo.created_at DESC, jo.id DESC
    LIMIT 10");
$recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <?php erp_render_breadcrumb([
        ['label' => 'Tổng quan', 'url' => erp_url('dashboard.php')],
        ['label' => 'Sản xuất'],
    ]); ?>

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Dashboard sản xuất</h1>
            <p class="text-muted mb-0">Tổng quan tình trạng phiếu gia công, nhập kho, xuất kho và giao hàng trong ngày.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-primary" href="<?= erp_h(erp_url('modules/production/job_order_create.php')) ?>"><i class="fa-solid fa-file-circle-plus me-2"></i>Tạo phiếu GC</a>
            <a class="btn btn-outline-success" href="<?= erp_h(erp_url('modules/production/receipt.php')) ?>"><i class="fa-solid fa-box-open me-2"></i>Nhập kho</a>
            <a class="btn btn-outline-info" href="<?= erp_h(erp_url('modules/production/output.php')) ?>"><i class="fa-solid fa-truck-ramp-box me-2"></i>Xuất kho</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6 col-xl-3">
            <div class="card shadow-sm border-0 h-100"><div class="card-body"><div class="d-flex justify-content-between"><div><div class="text-muted small">Phiếu GC đang xử lý</div><div class="display-6 fw-semibold"><?= $stats['processing'] ?></div></div><i class="fa-solid fa-gears fs-2 text-primary"></i></div></div></div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card shadow-sm border-0 h-100"><div class="card-body"><div class="d-flex justify-content-between"><div><div class="text-muted small">Hôm nay nhập kho</div><div class="display-6 fw-semibold"><?= $stats['receipts_today'] ?></div></div><i class="fa-solid fa-right-to-bracket fs-2 text-success"></i></div></div></div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card shadow-sm border-0 h-100"><div class="card-body"><div class="d-flex justify-content-between"><div><div class="text-muted small">Hôm nay xuất kho</div><div class="display-6 fw-semibold"><?= $stats['outputs_today'] ?></div></div><i class="fa-solid fa-arrow-up-right-from-square fs-2 text-info"></i></div></div></div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card shadow-sm border-0 h-100"><div class="card-body"><div class="d-flex justify-content-between"><div><div class="text-muted small">Hôm nay giao hàng</div><div class="display-6 fw-semibold"><?= $stats['deliveries_today'] ?></div></div><i class="fa-solid fa-truck-fast fs-2 text-warning"></i></div></div></div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white"><h2 class="h5 mb-0">Phiếu gia công theo trạng thái trong tháng</h2></div>
                <div class="card-body">
                    <?php if (!$statusRows): ?>
                        <p class="text-muted mb-0">Chưa có dữ liệu trong tháng này.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="table-light"><tr><th>Trạng thái</th><th>Số lượng</th><th class="w-50">Tỷ lệ</th></tr></thead>
                                <tbody>
                                <?php foreach ($statusRows as $row): ?>
                                    <?php $percent = $totalMonthOrders > 0 ? ((int) $row['total'] / $totalMonthOrders) * 100 : 0; ?>
                                    <tr>
                                        <td><span class="badge text-bg-<?= erp_h(erp_status_badge_class($row['status'])) ?>"><?= erp_h(erp_status_label($row['status'])) ?></span></td>
                                        <td><?= (int) $row['total'] ?></td>
                                        <td>
                                            <div class="progress" style="height: 10px;"><div class="progress-bar bg-<?= erp_h(erp_status_badge_class($row['status'])) ?>" style="width: <?= max(4, (int) round($percent)) ?>%"></div></div>
                                            <small class="text-muted"><?= number_format($percent, 1) ?>%</small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-xl-8">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h2 class="h5 mb-0">Phiếu gia công gần đây</h2>
                    <a class="btn btn-sm btn-outline-primary" href="<?= erp_h(erp_url('modules/production/job_orders.php')) ?>">Xem tất cả</a>
                </div>
                <div class="card-body table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Mã phiếu</th>
                            <th>Khách hàng</th>
                            <th>Ngày nhận</th>
                            <th>Hạn giao</th>
                            <th>Trạng thái</th>
                            <th>Số dòng</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$recentOrders): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">Chưa có phiếu gia công.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($recentOrders as $order): ?>
                            <tr>
                                <td class="fw-semibold"><?= erp_h($order['job_code']) ?></td>
                                <td><?= erp_h($order['customer_name']) ?></td>
                                <td><?= erp_h(erp_format_date($order['received_date'])) ?></td>
                                <td><?= erp_h(erp_format_date($order['due_date'])) ?></td>
                                <td><span class="badge text-bg-<?= erp_h(erp_status_badge_class($order['status'])) ?>"><?= erp_h(erp_status_label($order['status'])) ?></span></td>
                                <td><?= (int) $order['items_count'] ?></td>
                                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= erp_h(erp_url('modules/production/job_order_detail.php?id=' . (int) $order['id'])) ?>">Chi tiết</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
