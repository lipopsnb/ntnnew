<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

requireRole(['director', 'manager', 'warehouse', 'production']);
$pdo = erp_db();
$errors = [];

$jobOrders = $pdo->query("SELECT jo.id, jo.job_code, jo.received_date, c.name AS customer_name
    FROM job_orders jo
    INNER JOIN customers c ON c.id = jo.customer_id
    WHERE jo.status IN ('draft', 'in_progress', 'done')
    ORDER BY jo.received_date DESC, jo.id DESC")->fetchAll(PDO::FETCH_ASSOC);

$jobOrderItemsMap = [];
$itemSql = "SELECT joi.id, joi.job_order_id, joi.product_code_id, joi.qty_received, pc.code, pc.name, pc.unit
    FROM job_order_items joi
    INNER JOIN product_codes pc ON pc.id = joi.product_code_id
    ORDER BY joi.job_order_id ASC, joi.id ASC";
foreach ($pdo->query($itemSql)->fetchAll(PDO::FETCH_ASSOC) as $item) {
    $jobOrderItemsMap[(int) $item['job_order_id']][] = $item;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jobOrderId = (int) ($_POST['job_order_id'] ?? 0);
    $receiptDate = (string) ($_POST['receipt_date'] ?? date('Y-m-d'));
    $receivedBy = trim((string) ($_POST['received_by'] ?? erp_current_username()));
    $quantities = $_POST['qty'] ?? [];

    if (!erp_validate_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'CSRF token không hợp lệ.';
    }
    if ($jobOrderId <= 0 || $receiptDate === '' || $receivedBy === '') {
        $errors[] = 'Vui lòng chọn phiếu gia công, ngày nhập và người nhận.';
    }

    $items = [];
    foreach ($quantities as $itemId => $qty) {
        $qty = (float) $qty;
        if ($qty > 0) {
            $items[] = ['job_order_item_id' => (int) $itemId, 'qty' => $qty];
        }
    }

    if (!$items) {
        $errors[] = 'Cần nhập ít nhất một dòng số lượng.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO warehouse_receipts (job_order_id, receipt_date, received_by, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())');
            $stmt->execute([$jobOrderId, $receiptDate, $receivedBy]);
            $receiptId = (int) $pdo->lastInsertId();

            $insertItem = $pdo->prepare('INSERT INTO warehouse_receipt_items (warehouse_receipt_id, job_order_item_id, product_code_id, qty, created_at, updated_at) SELECT ?, joi.id, joi.product_code_id, ?, NOW(), NOW() FROM job_order_items joi WHERE joi.id = ? AND joi.job_order_id = ?');
            foreach ($items as $item) {
                $insertItem->execute([$receiptId, $item['qty'], $item['job_order_item_id'], $jobOrderId]);
            }

            $pdo->prepare("UPDATE job_orders SET status = 'in_progress', updated_at = NOW() WHERE id = ? AND status = 'draft'")->execute([$jobOrderId]);
            $pdo->commit();
            erp_flash('success', 'Đã tạo phiếu nhập kho.');
            erp_redirect(erp_url('modules/production/receipt.php'));
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Không thể tạo phiếu nhập kho: ' . $throwable->getMessage();
        }
    }
}

$receipts = $pdo->query("SELECT wr.id, wr.receipt_date, wr.received_by, jo.job_code, c.name AS customer_name
    FROM warehouse_receipts wr
    INNER JOIN job_orders jo ON jo.id = wr.job_order_id
    INNER JOIN customers c ON c.id = jo.customer_id
    ORDER BY wr.receipt_date DESC, wr.id DESC
    LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
$csrfToken = erp_csrf_token();
$flashes = erp_pull_flashes();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <?php erp_render_breadcrumb([
        ['label' => 'Tổng quan', 'url' => erp_url('dashboard.php')],
        ['label' => 'Sản xuất', 'url' => erp_url('modules/production/index.php')],
        ['label' => 'Nhập kho hàng khách'],
    ]); ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-1">Nhập kho hàng khách</h1>
            <p class="text-muted mb-0">Lập phiếu nhập kho nguyên vật liệu/chi tiết khách giao cho sản xuất.</p>
        </div>
    </div>

    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= erp_h($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= erp_h($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= erp_h($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white"><h2 class="h5 mb-0">Danh sách phiếu nhập kho</h2></div>
                <div class="card-body table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Phiếu GC</th><th>Khách hàng</th><th>Ngày nhập</th><th>Người nhận</th></tr></thead>
                        <tbody>
                        <?php if (!$receipts): ?><tr><td colspan="4" class="text-center text-muted py-4">Chưa có phiếu nhập kho.</td></tr><?php endif; ?>
                        <?php foreach ($receipts as $receipt): ?>
                            <tr>
                                <td class="fw-semibold"><?= erp_h($receipt['job_code']) ?></td>
                                <td><?= erp_h($receipt['customer_name']) ?></td>
                                <td><?= erp_h(erp_format_date($receipt['receipt_date'])) ?></td>
                                <td><?= erp_h($receipt['received_by']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white"><h2 class="h5 mb-0">Tạo phiếu nhập kho</h2></div>
                <div class="card-body">
                    <form method="post" id="receiptForm" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= erp_h($csrfToken) ?>">
                        <div class="col-12">
                            <label class="form-label">Phiếu gia công</label>
                            <input class="form-control" list="jobOrderList" id="jobOrderSearch" placeholder="Nhập mã phiếu hoặc khách hàng">
                            <datalist id="jobOrderList">
                                <?php foreach ($jobOrders as $jobOrder): ?>
                                    <option value="<?= erp_h($jobOrder['job_code'] . ' - ' . $jobOrder['customer_name']) ?>" data-id="<?= (int) $jobOrder['id'] ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <select class="form-select mt-2" name="job_order_id" id="jobOrderSelect" required>
                                <option value="">Chọn phiếu gia công</option>
                                <?php foreach ($jobOrders as $jobOrder): ?>
                                    <option value="<?= (int) $jobOrder['id'] ?>"><?= erp_h($jobOrder['job_code'] . ' - ' . $jobOrder['customer_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ngày nhập</label>
                            <input type="date" class="form-control" name="receipt_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Người nhận</label>
                            <input type="text" class="form-control" name="received_by" value="<?= erp_h(erp_current_username()) ?>" required>
                        </div>
                        <div class="col-12">
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead class="table-light"><tr><th>Mã SP</th><th>Tên SP</th><th>Qty</th></tr></thead>
                                    <tbody id="receiptItemsBody"><tr><td colspan="3" class="text-center text-muted py-3">Chọn phiếu gia công để tải sản phẩm.</td></tr></tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-12 d-flex justify-content-end gap-2">
                            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Lưu phiếu nhập</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
const receiptItemsMap = <?= json_encode($jobOrderItemsMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
function renderReceiptItems(jobOrderId) {
    const body = document.getElementById('receiptItemsBody');
    const items = receiptItemsMap[jobOrderId] || [];
    if (!items.length) {
        body.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">Không có chi tiết sản phẩm.</td></tr>';
        return;
    }
    body.innerHTML = items.map(item => `
        <tr>
            <td>${item.code}</td>
            <td>${item.name}</td>
            <td><input type="number" min="0" step="0.01" class="form-control" name="qty[${item.id}]" value="${item.qty_received || ''}"></td>
        </tr>`).join('');
}

document.getElementById('jobOrderSelect').addEventListener('change', function () {
    renderReceiptItems(this.value);
});

document.getElementById('jobOrderSearch').addEventListener('change', function () {
    const match = Array.from(document.getElementById('jobOrderSelect').options).find((option) => option.text === this.value);
    if (match) {
        document.getElementById('jobOrderSelect').value = match.value;
        renderReceiptItems(match.value);
    }
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
