<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

requireRole(['director', 'accountant']);
$pdo = erp_db();
$errors = [];

$deliveries = $pdo->query("SELECT d.id, d.delivery_code, d.customer_id, d.delivery_date, c.name AS customer_name, c.code AS customer_code
    FROM deliveries d
    INNER JOIN customers c ON c.id = d.customer_id
    LEFT JOIN invoices i ON i.delivery_id = d.id
    WHERE i.id IS NULL
    ORDER BY d.delivery_date DESC, d.id DESC")->fetchAll(PDO::FETCH_ASSOC);

$deliveryItemMap = [];
$itemSql = "SELECT di.id, di.delivery_id, di.job_order_item_id, di.qty_delivered, di.unit_price, di.amount, pc.code, pc.name, pc.unit
    FROM delivery_items di
    INNER JOIN job_order_items joi ON joi.id = di.job_order_item_id
    INNER JOIN product_codes pc ON pc.id = joi.product_code_id
    ORDER BY di.delivery_id ASC, di.id ASC";
foreach ($pdo->query($itemSql)->fetchAll(PDO::FETCH_ASSOC) as $item) {
    $deliveryItemMap[(int) $item['delivery_id']][] = $item;
}

$formData = [
    'invoice_code' => erp_generate_code($pdo, 'invoices', 'invoice_code', 'INV'),
    'delivery_id' => 0,
    'customer_id' => 0,
    'invoice_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+30 days')),
    'tax_rate' => 10,
    'note' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['invoice_code'] = trim((string) ($_POST['invoice_code'] ?? $formData['invoice_code']));
    $formData['delivery_id'] = (int) ($_POST['delivery_id'] ?? 0);
    $formData['customer_id'] = (int) ($_POST['customer_id'] ?? 0);
    $formData['invoice_date'] = (string) ($_POST['invoice_date'] ?? date('Y-m-d'));
    $formData['due_date'] = (string) ($_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days')));
    $formData['tax_rate'] = (float) ($_POST['tax_rate'] ?? 0);
    $formData['note'] = trim((string) ($_POST['note'] ?? ''));

    if (!erp_validate_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'CSRF token không hợp lệ.';
    }
    if ($formData['delivery_id'] <= 0 || $formData['customer_id'] <= 0 || $formData['invoice_date'] === '' || $formData['due_date'] === '') {
        $errors[] = 'Vui lòng chọn phiếu giao và đầy đủ ngày hóa đơn.';
    }

    $items = $deliveryItemMap[$formData['delivery_id']] ?? [];
    if (!$items) {
        $errors[] = 'Phiếu giao hàng chưa có dòng chi tiết.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $subtotal = array_sum(array_map(static fn(array $item): float => (float) $item['amount'], $items));
            $taxAmount = $subtotal * ((float) $formData['tax_rate'] / 100);
            $totalAmount = $subtotal + $taxAmount;
            $stmt = $pdo->prepare('INSERT INTO invoices (invoice_code, delivery_id, customer_id, invoice_date, due_date, subtotal, tax_rate, tax_amount, total_amount, paid_amount, status, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, NOW(), NOW())');
            $status = strtotime($formData['due_date']) < strtotime(date('Y-m-d')) ? 'overdue' : 'unpaid';
            $stmt->execute([$formData['invoice_code'], $formData['delivery_id'], $formData['customer_id'], $formData['invoice_date'], $formData['due_date'], $subtotal, $formData['tax_rate'], $taxAmount, $totalAmount, $status, $formData['note']]);
            $invoiceId = (int) $pdo->lastInsertId();
            if (erp_table_exists($pdo, 'invoice_items')) {
                $insertItem = $pdo->prepare('INSERT INTO invoice_items (invoice_id, delivery_item_id, description, qty, unit_price, amount, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())');
                foreach ($items as $item) {
                    $insertItem->execute([$invoiceId, $item['id'], $item['code'] . ' - ' . $item['name'], $item['qty_delivered'], $item['unit_price'], $item['amount']]);
                }
            }
            $pdo->commit();
            erp_flash('success', 'Đã tạo hóa đơn.');
            erp_redirect(erp_url('modules/invoice/index.php'));
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Không thể tạo hóa đơn: ' . $throwable->getMessage();
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
        ['label' => 'Hóa đơn', 'url' => erp_url('modules/invoice/index.php')],
        ['label' => 'Tạo hóa đơn'],
    ]); ?>

    <div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-1">Tạo hóa đơn</h1><p class="text-muted mb-0">Phát hành hóa đơn từ các phiếu giao hàng chưa xuất hóa đơn.</p></div></div>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= erp_h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <form method="post" id="invoiceForm">
        <input type="hidden" name="csrf_token" value="<?= erp_h($csrfToken) ?>">
        <div class="card shadow-sm border-0 mb-4"><div class="card-body row g-3"><div class="col-md-3"><label class="form-label">Mã HĐ</label><input type="text" class="form-control" name="invoice_code" value="<?= erp_h($formData['invoice_code']) ?>" readonly></div><div class="col-md-3"><label class="form-label">Phiếu giao</label><select class="form-select" name="delivery_id" id="deliverySelect" required><option value="">Chọn phiếu giao</option><?php foreach ($deliveries as $delivery): ?><option value="<?= (int) $delivery['id'] ?>" data-customer-id="<?= (int) $delivery['customer_id'] ?>" data-customer-name="<?= erp_h($delivery['customer_code'] . ' - ' . $delivery['customer_name']) ?>" <?= $formData['delivery_id'] === (int) $delivery['id'] ? 'selected' : '' ?>><?= erp_h($delivery['delivery_code'] . ' - ' . $delivery['customer_name']) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Khách hàng</label><input type="hidden" name="customer_id" id="invoiceCustomerId" value="<?= (int) $formData['customer_id'] ?>"><input type="text" class="form-control" id="invoiceCustomerText" readonly></div><div class="col-md-3"><label class="form-label">Ngày hóa đơn</label><input type="date" class="form-control" name="invoice_date" value="<?= erp_h($formData['invoice_date']) ?>" required></div><div class="col-md-3"><label class="form-label">Hạn thanh toán</label><input type="date" class="form-control" name="due_date" value="<?= erp_h($formData['due_date']) ?>" required></div><div class="col-md-3"><label class="form-label">Thuế suất</label><select class="form-select" name="tax_rate" id="taxRateSelect"><?php foreach ([0,5,8,10] as $taxRate): ?><option value="<?= $taxRate ?>" <?= (float) $formData['tax_rate'] === (float) $taxRate ? 'selected' : '' ?>><?= $taxRate ?>%</option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Ghi chú</label><input type="text" class="form-control" name="note" value="<?= erp_h($formData['note']) ?>"></div></div></div>
        <div class="card shadow-sm border-0"><div class="card-header bg-white"><h2 class="h5 mb-0">Chi tiết hóa đơn</h2></div><div class="card-body table-responsive"><table class="table align-middle mb-0"><thead class="table-light"><tr><th>Mã SP</th><th>Tên sản phẩm</th><th>Đơn vị</th><th>SL</th><th>Đơn giá</th><th>Thành tiền</th></tr></thead><tbody id="invoiceItemsBody"><tr><td colspan="6" class="text-center text-muted py-3">Chọn phiếu giao hàng để tải chi tiết.</td></tr></tbody><tfoot><tr><th colspan="5" class="text-end">Subtotal</th><th id="subtotalCell">0 đ</th></tr><tr><th colspan="5" class="text-end">Thuế</th><th id="taxAmountCell">0 đ</th></tr><tr><th colspan="5" class="text-end">Tổng cộng</th><th id="totalAmountCell">0 đ</th></tr></tfoot></table></div><div class="card-footer bg-white d-flex justify-content-end gap-2"><a class="btn btn-outline-secondary" href="<?= erp_h(erp_url('modules/invoice/index.php')) ?>">Hủy</a><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Lưu hóa đơn</button></div></div>
    </form>
</div>
<script>
const deliveryItemMapInvoice = <?= json_encode($deliveryItemMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const deliverySelect = document.getElementById('deliverySelect');
function formatVnd(value) { return new Intl.NumberFormat('vi-VN').format(Number(value || 0)) + ' đ'; }
function syncInvoiceHeader() {
    const option = deliverySelect.options[deliverySelect.selectedIndex];
    document.getElementById('invoiceCustomerId').value = option?.dataset.customerId || '';
    document.getElementById('invoiceCustomerText').value = option?.dataset.customerName || '';
}
function renderInvoiceItems() {
    syncInvoiceHeader();
    const tbody = document.getElementById('invoiceItemsBody');
    const items = deliveryItemMapInvoice[deliverySelect.value] || [];
    if (!items.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Không có dữ liệu chi tiết.</td></tr>';
        document.getElementById('subtotalCell').textContent = formatVnd(0);
        document.getElementById('taxAmountCell').textContent = formatVnd(0);
        document.getElementById('totalAmountCell').textContent = formatVnd(0);
        return;
    }
    let subtotal = 0;
    tbody.innerHTML = items.map(item => {
        subtotal += Number(item.amount || 0);
        return `<tr><td>${item.code}</td><td>${item.name}</td><td>${item.unit}</td><td>${item.qty_delivered}</td><td>${formatVnd(item.unit_price)}</td><td>${formatVnd(item.amount)}</td></tr>`;
    }).join('');
    const taxRate = Number(document.getElementById('taxRateSelect').value || 0);
    const taxAmount = subtotal * taxRate / 100;
    document.getElementById('subtotalCell').textContent = formatVnd(subtotal);
    document.getElementById('taxAmountCell').textContent = formatVnd(taxAmount);
    document.getElementById('totalAmountCell').textContent = formatVnd(subtotal + taxAmount);
}
deliverySelect.addEventListener('change', renderInvoiceItems);
document.getElementById('taxRateSelect').addEventListener('change', renderInvoiceItems);
renderInvoiceItems();
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
