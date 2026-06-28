<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

requireRole(['director', 'manager', 'production', 'warehouse']);
$pdo = erp_db();

$filters = [
    'status' => trim((string) ($_GET['status'] ?? '')),
    'customer_id' => (int) ($_GET['customer_id'] ?? 0),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];
if ($filters['status'] !== '') {
    $where[] = 'jo.status = ?';
    $params[] = $filters['status'];
}
if ($filters['customer_id'] > 0) {
    $where[] = 'jo.customer_id = ?';
    $params[] = $filters['customer_id'];
}
if ($filters['date_from'] !== '') {
    $where[] = 'jo.received_date >= ?';
    $params[] = $filters['date_from'];
}
if ($filters['date_to'] !== '') {
    $where[] = 'jo.received_date <= ?';
    $params[] = $filters['date_to'];
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM job_orders jo WHERE ' . implode(' AND ', $where));
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

$listSql = 'SELECT jo.*, c.name AS customer_name,
    (SELECT COUNT(*) FROM job_order_items joi WHERE joi.job_order_id = jo.id) AS items_count
    FROM job_orders jo
    INNER JOIN customers c ON c.id = jo.customer_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY jo.received_date DESC, jo.id DESC
    LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$jobOrders = $listStmt->fetchAll(PDO::FETCH_ASSOC);
$customers = $pdo->query('SELECT id, code, name FROM customers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$csrfToken = erp_csrf_token();
$flashes = erp_pull_flashes();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <?php erp_render_breadcrumb([
        ['label' => 'Tổng quan', 'url' => erp_url('dashboard.php')],
        ['label' => 'Sản xuất', 'url' => erp_url('modules/production/index.php')],
        ['label' => 'Phiếu gia công'],
    ]); ?>

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
        <div>
            <h1 class="h3 mb-1">Danh sách phiếu gia công</h1>
            <p class="text-muted mb-0">Theo dõi tiến độ nhận hàng, gia công và bàn giao theo từng khách hàng.</p>
        </div>
        <a class="btn btn-primary" href="<?= erp_h(erp_url('modules/production/job_order_create.php')) ?>"><i class="fa-solid fa-plus me-2"></i>Tạo phiếu mới</a>
    </div>

    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= erp_h($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= erp_h($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <form class="row g-3" method="get">
                <div class="col-md-3">
                    <label class="form-label">Trạng thái</label>
                    <select class="form-select" name="status">
                        <option value="">Tất cả</option>
                        <?php foreach (['draft', 'in_progress', 'done', 'delivered', 'cancelled'] as $status): ?>
                            <option value="<?= erp_h($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= erp_h(erp_status_label($status)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Khách hàng</label>
                    <select class="form-select" name="customer_id">
                        <option value="0">Tất cả khách hàng</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= (int) $customer['id'] ?>" <?= $filters['customer_id'] === (int) $customer['id'] ? 'selected' : '' ?>><?= erp_h($customer['code'] . ' - ' . $customer['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Từ ngày</label>
                    <input type="date" class="form-control" name="date_from" value="<?= erp_h($filters['date_from']) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Đến ngày</label>
                    <input type="date" class="form-control" name="date_to" value="<?= erp_h($filters['date_to']) ?>">
                </div>
                <div class="col-md-2 align-self-end d-flex gap-2">
                    <button class="btn btn-outline-primary flex-fill" type="submit">Lọc</button>
                    <a class="btn btn-outline-secondary" href="<?= erp_h(erp_url('modules/production/job_orders.php')) ?>">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
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
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$jobOrders): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Không tìm thấy phiếu gia công.</td></tr>
                <?php endif; ?>
                <?php foreach ($jobOrders as $order): ?>
                    <tr>
                        <td class="fw-semibold"><?= erp_h($order['job_code']) ?></td>
                        <td><?= erp_h($order['customer_name']) ?></td>
                        <td><?= erp_h(erp_format_date($order['received_date'])) ?></td>
                        <td><?= erp_h(erp_format_date($order['due_date'])) ?></td>
                        <td><span class="badge text-bg-<?= erp_h(erp_status_badge_class($order['status'])) ?>"><?= erp_h(erp_status_label($order['status'])) ?></span></td>
                        <td><?= (int) $order['items_count'] ?></td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a class="btn btn-outline-primary" href="<?= erp_h(erp_url('modules/production/job_order_detail.php?id=' . (int) $order['id'])) ?>">Xem chi tiết</a>
                                <?php if ($order['status'] === 'draft'): ?>
                                    <a class="btn btn-outline-secondary" href="<?= erp_h(erp_url('modules/production/job_order_create.php?id=' . (int) $order['id'])) ?>">Sửa</a>
                                <?php endif; ?>
                                <button
                                    class="btn btn-outline-info status-trigger"
                                    type="button"
                                    data-id="<?= (int) $order['id'] ?>"
                                    data-code="<?= erp_h($order['job_code']) ?>"
                                    data-status="<?= erp_h($order['status']) ?>"
                                    data-bs-toggle="modal"
                                    data-bs-target="#statusModal"
                                >Cập nhật trạng thái</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-white">
                <nav>
                    <ul class="pagination mb-0 justify-content-end">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <?php $query = http_build_query(array_merge($_GET, ['page' => $i])); ?>
                            <li class="page-item <?= $page === $i ? 'active' : '' ?>"><a class="page-link" href="?<?= erp_h($query) ?>"><?= $i ?></a></li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="statusForm">
                <div class="modal-header">
                    <h5 class="modal-title">Cập nhật trạng thái phiếu <span id="statusJobCode"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= erp_h($csrfToken) ?>">
                    <input type="hidden" name="job_order_id" id="statusJobOrderId">
                    <div class="mb-3">
                        <label class="form-label">Trạng thái mới</label>
                        <select class="form-select" name="new_status" id="newStatusSelect" required>
                            <?php foreach (['draft', 'in_progress', 'done', 'delivered', 'cancelled'] as $status): ?>
                                <option value="<?= erp_h($status) ?>"><?= erp_h(erp_status_label($status)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="small text-muted" id="statusMessage">Chỉ cho phép chuyển trạng thái hợp lệ theo quy trình.</div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Đóng</button>
                    <button class="btn btn-primary" type="submit">Cập nhật</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('statusModal');
    const modal = new bootstrap.Modal(modalEl);
    document.querySelectorAll('.status-trigger').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('statusJobOrderId').value = button.dataset.id;
            document.getElementById('statusJobCode').textContent = button.dataset.code || '';
            document.getElementById('newStatusSelect').value = button.dataset.status || 'draft';
        });
    });

    document.getElementById('statusForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(event.currentTarget);
        const response = await fetch('<?= erp_h(erp_url('api/update_job_status.php')) ?>', {
            method: 'POST',
            body: formData,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });
        const result = await response.json();
        if (result.success) {
            window.location.reload();
            return;
        }
        document.getElementById('statusMessage').textContent = result.message || 'Không thể cập nhật trạng thái.';
        document.getElementById('statusMessage').classList.add('text-danger');
    });
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
