<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

requireRole(['director', 'manager', 'warehouse']);
$pdo = erp_db();

$filters = [
    'customer_id' => (int) ($_GET['customer_id'] ?? 0),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
];

$where = ['1=1'];
$params = [];
if ($filters['customer_id'] > 0) {
    $where[] = 'd.customer_id = ?';
    $params[] = $filters['customer_id'];
}
if ($filters['date_from'] !== '') {
    $where[] = 'd.delivery_date >= ?';
    $params[] = $filters['date_from'];
}
if ($filters['date_to'] !== '') {
    $where[] = 'd.delivery_date <= ?';
    $params[] = $filters['date_to'];
}

$sql = 'SELECT d.*, c.name AS customer_name, jo.job_code,
    (SELECT COUNT(*) FROM delivery_items di WHERE di.delivery_id = d.id) AS items_count
    FROM deliveries d
    INNER JOIN customers c ON c.id = d.customer_id
    INNER JOIN job_orders jo ON jo.id = d.job_order_id
    WHERE ' . implode(' AND ', $where) . ' ORDER BY d.delivery_date DESC, d.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
$customers = $pdo->query('SELECT id, code, name FROM customers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <?php erp_render_breadcrumb([
        ['label' => 'Tổng quan', 'url' => erp_url('dashboard.php')],
        ['label' => 'Giao hàng'],
    ]); ?>

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
        <div>
            <h1 class="h3 mb-1">Danh sách giao hàng</h1>
            <p class="text-muted mb-0">Theo dõi phiếu giao hàng theo khách hàng và thời gian giao nhận.</p>
        </div>
        <a class="btn btn-primary" href="<?= erp_h(erp_url('modules/delivery/create.php')) ?>"><i class="fa-solid fa-plus me-2"></i>Tạo phiếu giao hàng</a>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <form class="row g-3" method="get">
                <div class="col-md-4">
                    <label class="form-label">Khách hàng</label>
                    <select class="form-select" name="customer_id">
                        <option value="0">Tất cả</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= (int) $customer['id'] ?>" <?= $filters['customer_id'] === (int) $customer['id'] ? 'selected' : '' ?>><?= erp_h($customer['code'] . ' - ' . $customer['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label">Từ ngày</label><input type="date" class="form-control" name="date_from" value="<?= erp_h($filters['date_from']) ?>"></div>
                <div class="col-md-3"><label class="form-label">Đến ngày</label><input type="date" class="form-control" name="date_to" value="<?= erp_h($filters['date_to']) ?>"></div>
                <div class="col-md-2 align-self-end d-flex gap-2"><button class="btn btn-outline-primary flex-fill" type="submit">Lọc</button><a class="btn btn-outline-secondary" href="<?= erp_h(erp_url('modules/delivery/index.php')) ?>">Reset</a></div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Mã giao hàng</th><th>Khách hàng</th><th>Phiếu GC</th><th>Ngày giao</th><th>Người nhận</th><th>Tài xế</th><th>Số dòng</th></tr></thead>
                <tbody>
                <?php if (!$deliveries): ?><tr><td colspan="7" class="text-center text-muted py-4">Chưa có phiếu giao hàng.</td></tr><?php endif; ?>
                <?php foreach ($deliveries as $delivery): ?>
                    <tr>
                        <td class="fw-semibold"><?= erp_h($delivery['delivery_code']) ?></td>
                        <td><?= erp_h($delivery['customer_name']) ?></td>
                        <td><?= erp_h($delivery['job_code']) ?></td>
                        <td><?= erp_h(erp_format_date($delivery['delivery_date'])) ?></td>
                        <td><?= erp_h($delivery['recipient_name']) ?></td>
                        <td><?= erp_h($delivery['driver']) ?></td>
                        <td><?= (int) $delivery['items_count'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
