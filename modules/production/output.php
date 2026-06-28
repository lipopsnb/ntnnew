<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

requireRole(['director', 'manager', 'warehouse', 'production']);
$pdo = erp_db();
$errors = [];

$jobOrders = $pdo->query("SELECT jo.id, jo.job_code, c.name AS customer_name
    FROM job_orders jo
    INNER JOIN customers c ON c.id = jo.customer_id
    WHERE jo.status IN ('in_progress', 'done')
    ORDER BY jo.received_date DESC, jo.id DESC")->fetchAll(PDO::FETCH_ASSOC);

$itemSql = "SELECT joi.id, joi.job_order_id, pc.code, pc.name, pc.unit, joi.qty_ok, joi.qty_ng
    FROM job_order_items joi
    INNER JOIN product_codes pc ON pc.id = joi.product_code_id
    ORDER BY joi.job_order_id ASC, joi.id ASC";
$jobOrderItemMap = [];
foreach ($pdo->query($itemSql)->fetchAll(PDO::FETCH_ASSOC) as $item) {
    $jobOrderItemMap[(int) $item['job_order_id']][] = $item;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jobOrderId = (int) ($_POST['job_order_id'] ?? 0);
    $outputDate = (string) ($_POST['output_date'] ?? date('Y-m-d'));
    $outputBy = trim((string) ($_POST['output_by'] ?? erp_current_username()));
    $itemIds = $_POST['job_order_item_id'] ?? [];
    $qtyOks = $_POST['qty_ok'] ?? [];
    $qtyNgs = $_POST['qty_ng'] ?? [];

    if (!erp_validate_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'CSRF token không hợp lệ.';
    }
    if ($jobOrderId <= 0 || $outputDate === '' || $outputBy === '') {
        $errors[] = 'Vui lòng chọn phiếu gia công, ngày xuất và người xuất.';
    }

    $items = [];
    foreach ($itemIds as $index => $itemId) {
        $itemId = (int) $itemId;
        $qtyOk = max(0, (float) ($qtyOks[$index] ?? 0));
        $qtyNg = max(0, (float) ($qtyNgs[$index] ?? 0));
        if ($itemId > 0 && ($qtyOk > 0 || $qtyNg > 0)) {
            $items[] = ['job_order_item_id' => $itemId, 'qty_ok' => $qtyOk, 'qty_ng' => $qtyNg];
        }
    }

    if (!$items) {
        $errors[] = 'Cần ít nhất một dòng sản phẩm xuất kho.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO warehouse_outputs (job_order_id, output_date, output_by, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())');
            $stmt->execute([$jobOrderId, $outputDate, $outputBy]);
            $outputId = (int) $pdo->lastInsertId();

            $insertItem = $pdo->prepare('INSERT INTO warehouse_output_items (warehouse_output_id, job_order_item_id, product_code_id, qty_ok, qty_ng, created_at, updated_at) SELECT ?, joi.id, joi.product_code_id, ?, ?, NOW(), NOW() FROM job_order_items joi WHERE joi.id = ? AND joi.job_order_id = ?');
            foreach ($items as $item) {
                $insertItem->execute([$outputId, $item['qty_ok'], $item['qty_ng'], $item['job_order_item_id'], $jobOrderId]);
            }
            $pdo->commit();
            erp_flash('success', 'Đã tạo phiếu xuất kho.');
            erp_redirect(erp_url('modules/production/output.php'));
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Không thể tạo phiếu xuất kho: ' . $throwable->getMessage();
        }
    }
}

$outputs = $pdo->query("SELECT wo.id, wo.output_date, wo.output_by, jo.job_code, c.name AS customer_name
    FROM warehouse_outputs wo
    INNER JOIN job_orders jo ON jo.id = wo.job_order_id
    INNER JOIN customers c ON c.id = jo.customer_id
    ORDER BY wo.output_date DESC, wo.id DESC
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
        ['label' => 'Xuất kho thành phẩm'],
    ]); ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-1">Xuất kho thành phẩm</h1>
            <p class="text-muted mb-0">Ghi nhận xuất kho thành phẩm/NG từ phiếu gia công.</p>
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
                <div class="card-header bg-white"><h2 class="h5 mb-0">Danh sách phiếu xuất kho</h2></div>
                <div class="card-body table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Phiếu GC</th><th>Khách hàng</th><th>Ngày xuất</th><th>Người xuất</th></tr></thead>
                        <tbody>
                        <?php if (!$outputs): ?><tr><td colspan="4" class="text-center text-muted py-4">Chưa có phiếu xuất kho.</td></tr><?php endif; ?>
                        <?php foreach ($outputs as $output): ?>
                            <tr>
                                <td class="fw-semibold"><?= erp_h($output['job_code']) ?></td>
                                <td><?= erp_h($output['customer_name']) ?></td>
                                <td><?= erp_h(erp_format_date($output['output_date'])) ?></td>
                                <td><?= erp_h($output['output_by']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h2 class="h5 mb-0">Tạo phiếu xuất kho</h2>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addOutputRow"><i class="fa-solid fa-plus me-1"></i>Thêm dòng</button>
                </div>
                <div class="card-body">
                    <form method="post" id="outputForm" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= erp_h($csrfToken) ?>">
                        <div class="col-12">
                            <label class="form-label">Phiếu gia công</label>
                            <select class="form-select" name="job_order_id" id="outputJobOrderSelect" required>
                                <option value="">Chọn phiếu gia công</option>
                                <?php foreach ($jobOrders as $jobOrder): ?>
                                    <option value="<?= (int) $jobOrder['id'] ?>"><?= erp_h($jobOrder['job_code'] . ' - ' . $jobOrder['customer_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ngày xuất</label>
                            <input type="date" class="form-control" name="output_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Người xuất</label>
                            <input type="text" class="form-control" name="output_by" value="<?= erp_h(erp_current_username()) ?>" required>
                        </div>
                        <div class="col-12 table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="table-light"><tr><th>Job order item</th><th>Product code</th><th>Qty OK</th><th>Qty NG</th><th></th></tr></thead>
                                <tbody id="outputItemsBody"></tbody>
                            </table>
                        </div>
                        <div class="col-12 d-flex justify-content-end gap-2">
                            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Lưu phiếu xuất</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
const outputJobOrderItemMap = <?= json_encode($jobOrderItemMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const outputItemsBody = document.getElementById('outputItemsBody');

function outputOptions(jobOrderId, selectedId = '') {
    const items = outputJobOrderItemMap[jobOrderId] || [];
    return '<option value="">Chọn dòng phiếu</option>' + items.map(item => `<option value="${item.id}" ${String(selectedId) === String(item.id) ? 'selected' : ''}>${item.code} - ${item.name}</option>`).join('');
}

function syncOutputRow(row) {
    const jobOrderId = document.getElementById('outputJobOrderSelect').value;
    const items = outputJobOrderItemMap[jobOrderId] || [];
    const selected = items.find(item => String(item.id) === row.querySelector('.output-item-select').value) || {};
    row.querySelector('.product-code-cell').textContent = selected.code || '';
}

function addOutputRow(selectedId = '') {
    const jobOrderId = document.getElementById('outputJobOrderSelect').value;
    const row = document.createElement('tr');
    row.innerHTML = `
        <td><select class="form-select output-item-select" name="job_order_item_id[]" required>${outputOptions(jobOrderId, selectedId)}</select></td>
        <td class="product-code-cell"></td>
        <td><input type="number" min="0" step="0.01" class="form-control" name="qty_ok[]" value="0"></td>
        <td><input type="number" min="0" step="0.01" class="form-control" name="qty_ng[]" value="0"></td>
        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger remove-output-row"><i class="fa-solid fa-trash"></i></button></td>`;
    outputItemsBody.appendChild(row);
    row.querySelector('.output-item-select').addEventListener('change', () => syncOutputRow(row));
    row.querySelector('.remove-output-row').addEventListener('click', () => row.remove());
    syncOutputRow(row);
}

function resetOutputRows() {
    outputItemsBody.innerHTML = '';
    addOutputRow();
}

document.getElementById('outputJobOrderSelect').addEventListener('change', resetOutputRows);
document.getElementById('addOutputRow').addEventListener('click', () => addOutputRow());
resetOutputRows();
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
