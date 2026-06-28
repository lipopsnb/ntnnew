<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

requireRole(['director', 'manager', 'production', 'warehouse']);
$pdo = erp_db();
$jobOrderId = (int) ($_GET['id'] ?? $_POST['job_order_id'] ?? 0);
if ($jobOrderId <= 0) {
    erp_redirect(erp_url('modules/production/job_orders.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_quantities') {
    if (!erp_validate_csrf($_POST['csrf_token'] ?? null)) {
        erp_flash('danger', 'CSRF token không hợp lệ.');
    } else {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $qtyOk = max(0, (float) ($_POST['qty_ok'] ?? 0));
        $qtyNg = max(0, (float) ($_POST['qty_ng'] ?? 0));
        $stmt = $pdo->prepare('UPDATE job_order_items SET qty_ok = ?, qty_ng = ?, updated_at = NOW() WHERE id = ? AND job_order_id = ?');
        $stmt->execute([$qtyOk, $qtyNg, $itemId, $jobOrderId]);
        erp_flash('success', 'Đã cập nhật số lượng OK/NG.');
    }
    erp_redirect(erp_url('modules/production/job_order_detail.php?id=' . $jobOrderId));
}

$stmt = $pdo->prepare('SELECT jo.*, c.name AS customer_name, c.code AS customer_code FROM job_orders jo INNER JOIN customers c ON c.id = jo.customer_id WHERE jo.id = ? LIMIT 1');
$stmt->execute([$jobOrderId]);
$jobOrder = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$jobOrder) {
    erp_flash('danger', 'Không tìm thấy phiếu gia công.');
    erp_redirect(erp_url('modules/production/job_orders.php'));
}

$itemStmt = $pdo->prepare('SELECT joi.*, pc.code, pc.name, pc.unit FROM job_order_items joi INNER JOIN product_codes pc ON pc.id = joi.product_code_id WHERE joi.job_order_id = ? ORDER BY joi.id ASC');
$itemStmt->execute([$jobOrderId]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

$receiptStmt = $pdo->prepare('SELECT id, receipt_date, received_by FROM warehouse_receipts WHERE job_order_id = ? ORDER BY receipt_date DESC, id DESC');
$receiptStmt->execute([$jobOrderId]);
$receipts = $receiptStmt->fetchAll(PDO::FETCH_ASSOC);

$outputStmt = $pdo->prepare('SELECT id, output_date, output_by FROM warehouse_outputs WHERE job_order_id = ? ORDER BY output_date DESC, id DESC');
$outputStmt->execute([$jobOrderId]);
$outputs = $outputStmt->fetchAll(PDO::FETCH_ASSOC);

$deliveryStmt = $pdo->prepare('SELECT id, delivery_code, delivery_date, recipient_name FROM deliveries WHERE job_order_id = ? ORDER BY delivery_date DESC, id DESC');
$deliveryStmt->execute([$jobOrderId]);
$deliveries = $deliveryStmt->fetchAll(PDO::FETCH_ASSOC);

$invoiceStmt = $pdo->prepare('SELECT i.id, i.invoice_code, i.invoice_date, i.total_amount, i.status FROM invoices i LEFT JOIN deliveries d ON d.id = i.delivery_id WHERE d.job_order_id = ? ORDER BY i.invoice_date DESC, i.id DESC');
$invoiceStmt->execute([$jobOrderId]);
$invoices = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);

$timeline = ['draft' => 1, 'in_progress' => 2, 'done' => 3, 'delivered' => 4];
$currentStep = $timeline[$jobOrder['status']] ?? 0;
$csrfToken = erp_csrf_token();
$flashes = erp_pull_flashes();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <?php erp_render_breadcrumb([
        ['label' => 'Tổng quan', 'url' => erp_url('dashboard.php')],
        ['label' => 'Sản xuất', 'url' => erp_url('modules/production/index.php')],
        ['label' => 'Phiếu gia công', 'url' => erp_url('modules/production/job_orders.php')],
        ['label' => $jobOrder['job_code']],
    ]); ?>

    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= erp_h($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= erp_h($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Chi tiết phiếu gia công <?= erp_h($jobOrder['job_code']) ?></h1>
            <div class="text-muted">Khách hàng: <?= erp_h($jobOrder['customer_code'] . ' - ' . $jobOrder['customer_name']) ?></div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-outline-info" type="button" data-bs-toggle="modal" data-bs-target="#statusModal"><i class="fa-solid fa-arrows-rotate me-2"></i>Cập nhật trạng thái</button>
            <button class="btn btn-outline-secondary" type="button" onclick="window.print()"><i class="fa-solid fa-print me-2"></i>In phiếu</button>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body row g-3">
            <div class="col-md-3"><div class="text-muted small">Ngày nhận</div><div class="fw-semibold"><?= erp_h(erp_format_date($jobOrder['received_date'])) ?></div></div>
            <div class="col-md-3"><div class="text-muted small">Hạn giao</div><div class="fw-semibold"><?= erp_h(erp_format_date($jobOrder['due_date'])) ?></div></div>
            <div class="col-md-3"><div class="text-muted small">Trạng thái</div><span class="badge text-bg-<?= erp_h(erp_status_badge_class($jobOrder['status'])) ?>"><?= erp_h(erp_status_label($jobOrder['status'])) ?></span></div>
            <div class="col-md-3"><div class="text-muted small">Tổng giá trị</div><div class="fw-semibold"><?= erp_h(erp_format_vnd($jobOrder['total_amount'] ?? 0)) ?></div></div>
            <div class="col-12"><div class="text-muted small">Ghi chú</div><div><?= nl2br(erp_h($jobOrder['note'])) ?></div></div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white"><h2 class="h5 mb-0">Trạng thái xử lý</h2></div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-3">
                <?php $steps = ['draft' => 'Draft', 'in_progress' => 'In Progress', 'done' => 'Done', 'delivered' => 'Delivered']; ?>
                <?php $index = 1; foreach ($steps as $key => $label): ?>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge rounded-pill <?= $currentStep >= $index ? 'text-bg-primary' : 'text-bg-light text-dark' ?>"><?= $index ?></span>
                        <span class="<?= $currentStep >= $index ? 'fw-semibold' : 'text-muted' ?>"><?= erp_h($label) ?></span>
                    </div>
                <?php $index++; endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white"><h2 class="h5 mb-0">Danh sách chi tiết</h2></div>
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Mã SP</th>
                    <th>Tên SP</th>
                    <th>Qty nhận</th>
                    <th>Qty OK</th>
                    <th>Qty NG</th>
                    <th>Qty trả</th>
                    <th>Đơn giá</th>
                    <th>Thành tiền</th>
                    <th>Cập nhật</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <?php $formId = 'item-form-' . (int) $item['id']; ?>
                    <tr>
                        <td><?= erp_h($item['code']) ?></td>
                        <td><?= erp_h($item['name']) ?></td>
                        <td><?= rtrim(rtrim(number_format((float) $item['qty_received'], 2, '.', ''), '0'), '.') ?></td>
                        <td><input form="<?= erp_h($formId) ?>" type="number" min="0" step="0.01" class="form-control form-control-sm" style="min-width: 90px" name="qty_ok" value="<?= erp_h((string) $item['qty_ok']) ?>"></td>
                        <td><input form="<?= erp_h($formId) ?>" type="number" min="0" step="0.01" class="form-control form-control-sm" style="min-width: 90px" name="qty_ng" value="<?= erp_h((string) $item['qty_ng']) ?>"></td>
                        <td><?= rtrim(rtrim(number_format((float) $item['qty_returned'], 2, '.', ''), '0'), '.') ?></td>
                        <td class="text-nowrap"><?= erp_h(erp_format_vnd($item['unit_price'])) ?></td>
                        <td class="text-nowrap"><?= erp_h(erp_format_vnd($item['amount'])) ?></td>
                        <td>
                            <form id="<?= erp_h($formId) ?>" method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= erp_h($csrfToken) ?>">
                                <input type="hidden" name="action" value="update_quantities">
                                <input type="hidden" name="job_order_id" value="<?= $jobOrderId ?>">
                                <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                <button class="btn btn-sm btn-outline-primary" type="submit">Lưu</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100"><div class="card-header bg-white"><h2 class="h5 mb-0">Phiếu nhập kho liên quan</h2></div><div class="card-body"><?php if (!$receipts): ?><div class="text-muted">Chưa có phiếu nhập kho.</div><?php else: ?><ul class="list-group list-group-flush"><?php foreach ($receipts as $receipt): ?><li class="list-group-item px-0 d-flex justify-content-between"><span>Ngày <?= erp_h(erp_format_date($receipt['receipt_date'])) ?></span><span class="text-muted"><?= erp_h($receipt['received_by']) ?></span></li><?php endforeach; ?></ul><?php endif; ?></div></div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100"><div class="card-header bg-white"><h2 class="h5 mb-0">Phiếu xuất kho liên quan</h2></div><div class="card-body"><?php if (!$outputs): ?><div class="text-muted">Chưa có phiếu xuất kho.</div><?php else: ?><ul class="list-group list-group-flush"><?php foreach ($outputs as $output): ?><li class="list-group-item px-0 d-flex justify-content-between"><span>Ngày <?= erp_h(erp_format_date($output['output_date'])) ?></span><span class="text-muted"><?= erp_h($output['output_by']) ?></span></li><?php endforeach; ?></ul><?php endif; ?></div></div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100"><div class="card-header bg-white"><h2 class="h5 mb-0">Phiếu giao hàng liên quan</h2></div><div class="card-body"><?php if (!$deliveries): ?><div class="text-muted">Chưa có phiếu giao hàng.</div><?php else: ?><ul class="list-group list-group-flush"><?php foreach ($deliveries as $delivery): ?><li class="list-group-item px-0 d-flex justify-content-between"><span><?= erp_h($delivery['delivery_code']) ?> - <?= erp_h(erp_format_date($delivery['delivery_date'])) ?></span><span class="text-muted"><?= erp_h($delivery['recipient_name']) ?></span></li><?php endforeach; ?></ul><?php endif; ?></div></div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100"><div class="card-header bg-white"><h2 class="h5 mb-0">Hóa đơn liên quan</h2></div><div class="card-body"><?php if (!$invoices): ?><div class="text-muted">Chưa có hóa đơn.</div><?php else: ?><ul class="list-group list-group-flush"><?php foreach ($invoices as $invoice): ?><li class="list-group-item px-0 d-flex justify-content-between"><span><?= erp_h($invoice['invoice_code']) ?> - <?= erp_h(erp_format_date($invoice['invoice_date'])) ?></span><span class="badge text-bg-<?= erp_h(erp_status_badge_class($invoice['status'])) ?>"><?= erp_h(erp_status_label($invoice['status'])) ?></span></li><?php endforeach; ?></ul><?php endif; ?></div></div>
        </div>
    </div>
</div>

<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="statusForm">
                <div class="modal-header"><h5 class="modal-title">Cập nhật trạng thái</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= erp_h($csrfToken) ?>">
                    <input type="hidden" name="job_order_id" value="<?= $jobOrderId ?>">
                    <label class="form-label">Trạng thái mới</label>
                    <select class="form-select" name="new_status">
                        <?php foreach (['draft', 'in_progress', 'done', 'delivered', 'cancelled'] as $status): ?>
                            <option value="<?= erp_h($status) ?>" <?= $jobOrder['status'] === $status ? 'selected' : '' ?>><?= erp_h(erp_status_label($status)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="small text-muted mt-2" id="statusResult">Cập nhật trạng thái sẽ lưu ngay.</div>
                </div>
                <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Đóng</button><button class="btn btn-primary" type="submit">Lưu</button></div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('statusForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const response = await fetch('<?= erp_h(erp_url('api/update_job_status.php')) ?>', {
        method: 'POST',
        body: new FormData(event.currentTarget),
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    });
    const result = await response.json();
    if (result.success) {
        window.location.reload();
        return;
    }
    const statusResult = document.getElementById('statusResult');
    statusResult.textContent = result.message || 'Không thể cập nhật trạng thái.';
    statusResult.classList.add('text-danger');
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
