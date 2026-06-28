<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

requireRole('director', 'manager', 'warehouse', 'production', 'accountant');
$pdo = erp_db();

$today = date('Y-m-d');
$monthStart = date('Y-m-01');

$receiptsToday = (int) $pdo->prepare('SELECT COUNT(*) FROM warehouse_receipts WHERE receipt_date = ?')->execute([$today]) ? $pdo->query("SELECT COUNT(*) FROM warehouse_receipts WHERE receipt_date = '$today'")->fetchColumn() : 0;
$outputsToday  = (int) $pdo->query("SELECT COUNT(*) FROM warehouse_outputs WHERE output_date = '$today'")->fetchColumn();
$deliveriesToday = (int) $pdo->query("SELECT COUNT(*) FROM deliveries WHERE delivery_date = '$today'")->fetchColumn();
$pendingReceipt = (int) $pdo->query("SELECT COUNT(*) FROM job_orders WHERE status = 'draft'")->fetchColumn();
$inProgress    = (int) $pdo->query("SELECT COUNT(*) FROM job_orders WHERE status = 'in_progress'")->fetchColumn();

// Thống kê tháng
$stmtR = $pdo->prepare('SELECT COUNT(*) FROM warehouse_receipts WHERE receipt_date >= ?');
$stmtR->execute([$monthStart]);
$receiptsMonth = (int) $stmtR->fetchColumn();

$stmtO = $pdo->prepare('SELECT COUNT(*) FROM warehouse_outputs WHERE output_date >= ?');
$stmtO->execute([$monthStart]);
$outputsMonth = (int) $stmtO->fetchColumn();

$stmtD = $pdo->prepare('SELECT COUNT(*) FROM deliveries WHERE delivery_date >= ?');
$stmtD->execute([$monthStart]);
$deliveriesMonth = (int) $stmtD->fetchColumn();

// Phiếu nhập kho gần nhất
$recentReceipts = $pdo->query("
    SELECT wr.receipt_code, wr.receipt_date, wr.received_by, jo.job_code, c.name AS customer_name
    FROM warehouse_receipts wr
    INNER JOIN job_orders jo ON jo.id = wr.job_order_id
    INNER JOIN customers c ON c.id = jo.customer_id
    ORDER BY wr.created_at DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Phiếu giao hàng gần nhất
$recentDeliveries = $pdo->query("
    SELECT d.delivery_code, d.delivery_date, d.recipient_name, jo.job_code, c.name AS customer_name
    FROM deliveries d
    INNER JOIN job_orders jo ON jo.id = d.job_order_id
    INNER JOIN customers c ON c.id = d.customer_id
    ORDER BY d.created_at DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$flashes = erp_pull_flashes();
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <?php erp_render_breadcrumb([
        ['label' => 'Tổng quan', 'url' => erp_url('dashboard.php')],
        ['label' => 'Quản lý kho'],
    ]); ?>

    <div class="mb-3">
        <h1 class="h3 mb-1">Quản lý kho</h1>
        <p class="text-muted mb-0">Tổng quan tình trạng nhập/xuất kho và giao hàng.</p>
    </div>

    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= erp_h($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= erp_h($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <!-- Thống kê hôm nay -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-primary bg-opacity-10 p-3">
                        <i class="fas fa-file-import fa-lg text-primary"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Nhập kho hôm nay</div>
                        <div class="h4 mb-0 fw-bold"><?= $receiptsToday ?></div>
                        <div class="text-muted small">Cả tháng: <?= $receiptsMonth ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-success bg-opacity-10 p-3">
                        <i class="fas fa-file-export fa-lg text-success"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Xuất kho hôm nay</div>
                        <div class="h4 mb-0 fw-bold"><?= $outputsToday ?></div>
                        <div class="text-muted small">Cả tháng: <?= $outputsMonth ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-info bg-opacity-10 p-3">
                        <i class="fas fa-truck fa-lg text-info"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Giao hàng hôm nay</div>
                        <div class="h4 mb-0 fw-bold"><?= $deliveriesToday ?></div>
                        <div class="text-muted small">Cả tháng: <?= $deliveriesMonth ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-warning bg-opacity-10 p-3">
                        <i class="fas fa-hourglass-half fa-lg text-warning"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Phiếu đang SX</div>
                        <div class="h4 mb-0 fw-bold"><?= $inProgress ?></div>
                        <div class="text-muted small">Chờ nhập kho: <?= $pendingReceipt ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Thao tác nhanh -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <a href="<?= erp_h(erp_url('modules/warehouse/receipt.php')) ?>" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body text-center py-4">
                    <i class="fas fa-arrow-down fa-2x text-primary mb-2"></i>
                    <h5 class="mb-1">Nhập kho hàng khách</h5>
                    <p class="text-muted small mb-0">Lập phiếu nhập kho nguyên vật liệu</p>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="<?= erp_h(erp_url('modules/warehouse/output.php')) ?>" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body text-center py-4">
                    <i class="fas fa-arrow-up fa-2x text-success mb-2"></i>
                    <h5 class="mb-1">Xuất kho thành phẩm</h5>
                    <p class="text-muted small mb-0">Ghi nhận xuất kho sau QC</p>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="<?= erp_h(erp_url('modules/delivery/index.php')) ?>" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body text-center py-4">
                    <i class="fas fa-truck fa-2x text-info mb-2"></i>
                    <h5 class="mb-1">Giao hàng</h5>
                    <p class="text-muted small mb-0">Tạo và theo dõi phiếu giao hàng</p>
                </div>
            </a>
        </div>
    </div>

    <!-- Bảng tóm tắt gần nhất -->
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0">Nhập kho gần nhất</h2>
                    <a href="<?= erp_h(erp_url('modules/warehouse/receipt.php')) ?>" class="btn btn-sm btn-outline-primary">Xem tất cả</a>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>Mã phiếu</th><th>Phiếu GC</th><th>Khách hàng</th><th>Ngày nhập</th></tr>
                        </thead>
                        <tbody>
                        <?php if (!$recentReceipts): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">Chưa có phiếu nhập kho.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($recentReceipts as $r): ?>
                            <tr>
                                <td class="fw-semibold text-primary"><?= erp_h($r['receipt_code']) ?></td>
                                <td><?= erp_h($r['job_code']) ?></td>
                                <td><?= erp_h($r['customer_name']) ?></td>
                                <td><?= erp_h(erp_format_date($r['receipt_date'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0">Giao hàng gần nhất</h2>
                    <a href="<?= erp_h(erp_url('modules/delivery/index.php')) ?>" class="btn btn-sm btn-outline-primary">Xem tất cả</a>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>Mã phiếu</th><th>Phiếu GC</th><th>Khách hàng</th><th>Ngày giao</th></tr>
                        </thead>
                        <tbody>
                        <?php if (!$recentDeliveries): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">Chưa có phiếu giao hàng.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($recentDeliveries as $d): ?>
                            <tr>
                                <td class="fw-semibold text-info"><?= erp_h($d['delivery_code']) ?></td>
                                <td><?= erp_h($d['job_code']) ?></td>
                                <td><?= erp_h($d['customer_name']) ?></td>
                                <td><?= erp_h(erp_format_date($d['delivery_date'])) ?></td>
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
