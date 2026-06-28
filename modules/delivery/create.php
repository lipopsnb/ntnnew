<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

requireRole(['director', 'manager', 'warehouse']);
$pdo = erp_db();
$errors = [];

$jobOrders = $pdo->query("SELECT jo.id, jo.job_code, jo.customer_id, c.name AS customer_name, c.code AS customer_code
    FROM job_orders jo
    INNER JOIN customers c ON c.id = jo.customer_id
    WHERE jo.status = 'done'
    ORDER BY jo.due_date ASC, jo.id DESC")->fetchAll(PDO::FETCH_ASSOC);

$itemSql = "SELECT joi.id, joi.job_order_id, joi.product_code_id, joi.unit_price, joi.qty_ok,
        COALESCE((SELECT SUM(di.qty_delivered) FROM delivery_items di WHERE di.job_order_item_id = joi.id), 0) AS qty_delivered,
        pc.code, pc.name, pc.unit
    FROM job_order_items joi
    INNER JOIN product_codes pc ON pc.id = joi.product_code_id
    ORDER BY joi.job_order_id ASC, joi.id ASC";
$jobOrderItemMap = [];
foreach ($pdo->query($itemSql)->fetchAll(PDO::FETCH_ASSOC) as $item) {
    $available = (float) $item['qty_ok'] - (float) $item['qty_delivered'];
    if ($available <= 0) {
        continue;
    }
    $item['qty_available'] = $available;
    $jobOrderItemMap[(int) $item['job_order_id']][] = $item;
}

$formData = [
    'delivery_code' => erp_generate_code($pdo, 'deliveries', 'delivery_code', 'DL'),
    'job_order_id' => 0,
    'customer_id' => 0,
    'delivery_date' => date('Y-m-d'),
    'recipient_name' => '',
    'driver' => '',
    'note' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['delivery_code'] = trim((string) ($_POST['delivery_code'] ?? $formData['delivery_code']));
    $formData['job_order_id'] = (int) ($_POST['job_order_id'] ?? 0);
    $formData['customer_id'] = (int) ($_POST['customer_id'] ?? 0);
    $formData['delivery_date'] = (string) ($_POST['delivery_date'] ?? date('Y-m-d'));
    $formData['recipient_name'] = trim((string) ($_POST['recipient_name'] ?? ''));
    $formData['driver'] = trim((string) ($_POST['driver'] ?? ''));
    $formData['note'] = trim((string) ($_POST['note'] ?? ''));
    $itemIds = $_POST['job_order_item_id'] ?? [];
    $qtys = $_POST['qty_delivered'] ?? [];
    $prices = $_POST['unit_price'] ?? [];

    if (!erp_validate_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'CSRF token không hợp lệ.';
    }
    if ($formData['job_order_id'] <= 0 || $formData['customer_id'] <= 0 || $formData['delivery_date'] === '') {
        $errors[] = 'Vui lòng chọn phiếu gia công và ngày giao hàng.';
    }

    $allowedItems = [];
    foreach ($jobOrderItemMap[$formData['job_order_id']] ?? [] as $item) {
        $allowedItems[(int) $item['id']] = $item;
    }

    $items = [];
    foreach ($itemIds as $index => $itemId) {
        $itemId = (int) $itemId;
        $qty = max(0, (float) ($qtys[$index] ?? 0));
        $unitPrice = erp_to_decimal($prices[$index] ?? 0);
        if ($itemId <= 0 || $qty <= 0) {
            continue;
        }
        if (!isset($allowedItems[$itemId]) || $qty > (float) $allowedItems[$itemId]['qty_available']) {
            $errors[] = 'Số lượng giao vượt quá số lượng khả dụng.';
            break;
        }
        $items[] = ['job_order_item_id' => $itemId, 'qty_delivered' => $qty, 'unit_price' => $unitPrice, 'amount' => $qty * $unitPrice];
    }

    if (!$items) {
        $errors[] = 'Cần ít nhất một dòng hàng giao hợp lệ.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO deliveries (delivery_code, job_order_id, customer_id, delivery_date, recipient_name, driver, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([$formData['delivery_code'], $formData['job_order_id'], $formData['customer_id'], $formData['delivery_date'], $formData['recipient_name'], $formData['driver'], $formData['note']]);
            $deliveryId = (int) $pdo->lastInsertId();
            $insertItem = $pdo->prepare('INSERT INTO delivery_items (delivery_id, job_order_item_id, product_code_id, qty_delivered, unit_price, amount, created_at, updated_at) SELECT ?, joi.id, joi.product_code_id, ?, ?, ?, NOW(), NOW() FROM job_order_items joi WHERE joi.id = ? AND joi.job_order_id = ?');
            foreach ($items as $item) {
                $insertItem->execute([$deliveryId, $item['qty_delivered'], $item['unit_price'], $item['amount'], $item['job_order_item_id'], $formData['job_order_id']]);
            }
            $pdo->prepare("UPDATE job_orders SET status = 'delivered', updated_at = NOW() WHERE id = ?")->execute([$formData['job_order_id']]);
            $pdo->commit();
            erp_flash('success', 'Đã tạo phiếu giao hàng.');
            erp_redirect(erp_url('modules/delivery/index.php'));
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Không thể tạo phiếu giao hàng: ' . $throwable->getMessage();
        }
    }
}

$csrfToken = erp_csrf_token();
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <?php erp_render_breadcrumb([
        ['label' => 'Tổng quan', 'url' => erp_url('dashboard.php')],
        ['label' => 'Giao hàng', 'url' => erp_url('modules/delivery/index.php')],
        ['label' => 'Tạo phiếu giao hàng'],
    ]); ?>

    <div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-1">Tạo phiếu giao hàng</h1><p class="text-muted mb-0">Chọn phiếu đã hoàn thành và giao hàng theo số lượng OK khả dụng.</p></div></div>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= erp_h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <form method="post" id="deliveryForm">
        <input type="hidden" name="csrf_token" value="<?= erp_h($csrfToken) ?>">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body row g-3">
                <div class="col-md-3"><label class="form-label">Mã phiếu</label><input type="text" class="form-control" name="delivery_code" value="<?= erp_h($formData['delivery_code']) ?>" readonly></div>
                <div class="col-md-3"><label class="form-label">Phiếu gia công</label><select class="form-select" name="job_order_id" id="deliveryJobOrderSelect" required><option value="">Chọn phiếu</option><?php foreach ($jobOrders as $jobOrder): ?><option value="<?= (int) $jobOrder['id'] ?>" data-customer-id="<?= (int) $jobOrder['customer_id'] ?>" data-customer-name="<?= erp_h($jobOrder['customer_code'] . ' - ' . $jobOrder['customer_name']) ?>" <?= $formData['job_order_id'] === (int) $jobOrder['id'] ? 'selected' : '' ?>><?= erp_h($jobOrder['job_code'] . ' - ' . $jobOrder['customer_name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="form-label">Khách hàng</label><input type="hidden" name="customer_id" id="deliveryCustomerId" value="<?= (int) $formData['customer_id'] ?>"><input type="text" class="form-control" id="deliveryCustomerText" readonly></div>
                <div class="col-md-3"><label class="form-label">Ngày giao</label><input type="date" class="form-control" name="delivery_date" value="<?= erp_h($formData['delivery_date']) ?>" required></div>
                <div class="col-md-4"><label class="form-label">Người nhận</label><input type="text" class="form-control" name="recipient_name" value="<?= erp_h($formData['recipient_name']) ?>"></div>
                <div class="col-md-4"><label class="form-label">Tài xế</label><input type="text" class="form-control" name="driver" value="<?= erp_h($formData['driver']) ?>"></div>
                <div class="col-md-4"><label class="form-label">Ghi chú</label><input type="text" class="form-control" name="note" value="<?= erp_h($formData['note']) ?>"></div>
            </div>
        </div>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white d-flex justify-content-between align-items-center"><h2 class="h5 mb-0">Chi tiết giao hàng</h2><button type="button" class="btn btn-sm btn-outline-primary" id="addDeliveryRow"><i class="fa-solid fa-plus me-1"></i>Thêm dòng</button></div>
            <div class="card-body table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Job order item</th><th>Sản phẩm</th><th>Qty khả dụng</th><th>Qty giao</th><th>Đơn giá</th><th></th></tr></thead>
                    <tbody id="deliveryItemsBody"></tbody>
                </table>
            </div>
            <div class="card-footer bg-white d-flex justify-content-end gap-2"><a class="btn btn-outline-secondary" href="<?= erp_h(erp_url('modules/delivery/index.php')) ?>">Hủy</a><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Lưu phiếu giao</button></div>
        </div>
    </form>
</div>
<script>
const deliveryItemMap = <?= json_encode($jobOrderItemMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const deliveryBody = document.getElementById('deliveryItemsBody');
const deliveryJobOrderSelect = document.getElementById('deliveryJobOrderSelect');

function deliveryOptions(jobOrderId, selectedId = '') {
    return '<option value="">Chọn dòng phiếu</option>' + (deliveryItemMap[jobOrderId] || []).map(item => `<option value="${item.id}" ${String(selectedId) === String(item.id) ? 'selected' : ''}>${item.code} - ${item.name}</option>`).join('');
}

function syncDeliveryCustomer() {
    const option = deliveryJobOrderSelect.options[deliveryJobOrderSelect.selectedIndex];
    document.getElementById('deliveryCustomerId').value = option?.dataset.customerId || '';
    document.getElementById('deliveryCustomerText').value = option?.dataset.customerName || '';
}

function syncDeliveryRow(row) {
    const items = deliveryItemMap[deliveryJobOrderSelect.value] || [];
    const selected = items.find(item => String(item.id) === row.querySelector('.delivery-item-select').value) || {};
    row.querySelector('.delivery-product-cell').textContent = selected.code ? `${selected.code} - ${selected.name}` : '';
    row.querySelector('.delivery-available-cell').textContent = selected.qty_available || '';
    if (selected.unit_price) {
        row.querySelector('.delivery-price-input').value = selected.unit_price;
    }
}

function addDeliveryRow(selectedId = '') {
    const row = document.createElement('tr');
    row.innerHTML = `
        <td><select class="form-select delivery-item-select" name="job_order_item_id[]" required>${deliveryOptions(deliveryJobOrderSelect.value, selectedId)}</select></td>
        <td class="delivery-product-cell"></td>
        <td class="delivery-available-cell"></td>
        <td><input type="number" min="0" step="0.01" class="form-control" name="qty_delivered[]" value="0"></td>
        <td><input type="number" min="0" step="0.01" class="form-control delivery-price-input" name="unit_price[]" value="0"></td>
        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger remove-delivery-row"><i class="fa-solid fa-trash"></i></button></td>`;
    deliveryBody.appendChild(row);
    row.querySelector('.delivery-item-select').addEventListener('change', () => syncDeliveryRow(row));
    row.querySelector('.remove-delivery-row').addEventListener('click', () => row.remove());
    syncDeliveryRow(row);
}

function resetDeliveryRows() {
    deliveryBody.innerHTML = '';
    syncDeliveryCustomer();
    addDeliveryRow();
}

deliveryJobOrderSelect.addEventListener('change', resetDeliveryRows);
document.getElementById('addDeliveryRow').addEventListener('click', () => addDeliveryRow());
resetDeliveryRows();
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
