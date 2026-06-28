<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

requireRole(['director', 'manager', 'production']);
$pdo = erp_db();
$errors = [];
$orderId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $orderId > 0;

$formData = [
    'id' => $orderId,
    'job_code' => erp_generate_code($pdo, 'job_orders', 'job_code', 'JO'),
    'customer_id' => 0,
    'received_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+7 days')),
    'note' => '',
    'status' => 'draft',
    'items' => [],
];

if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM job_orders WHERE id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    $existingOrder = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existingOrder || $existingOrder['status'] !== 'draft') {
        erp_flash('danger', 'Chỉ được sửa phiếu ở trạng thái nháp.');
        erp_redirect(erp_url('modules/production/job_orders.php'));
    }

    $itemStmt = $pdo->prepare('SELECT joi.*, pc.code, pc.name, pc.unit FROM job_order_items joi INNER JOIN product_codes pc ON pc.id = joi.product_code_id WHERE joi.job_order_id = ? ORDER BY joi.id ASC');
    $itemStmt->execute([$orderId]);
    $existingItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    $formData = array_merge($formData, $existingOrder, ['id' => $orderId, 'items' => $existingItems]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['job_code'] = trim((string) ($_POST['job_code'] ?? $formData['job_code']));
    $formData['customer_id'] = (int) ($_POST['customer_id'] ?? 0);
    $formData['received_date'] = (string) ($_POST['received_date'] ?? '');
    $formData['due_date'] = (string) ($_POST['due_date'] ?? '');
    $formData['note'] = trim((string) ($_POST['note'] ?? ''));
    $productIds = $_POST['product_code_id'] ?? [];
    $quantities = $_POST['qty_received'] ?? [];
    $prices = $_POST['unit_price'] ?? [];

    if (!erp_validate_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Phiên làm việc hết hạn. Vui lòng tải lại trang.';
    }

    if ($formData['customer_id'] <= 0 || $formData['received_date'] === '' || $formData['due_date'] === '') {
        $errors[] = 'Khách hàng, ngày nhận và hạn giao là bắt buộc.';
    }

    $items = [];
    foreach ($productIds as $index => $productId) {
        $productId = (int) $productId;
        $qty = (float) ($quantities[$index] ?? 0);
        $unitPrice = erp_to_decimal($prices[$index] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            continue;
        }
        $items[] = [
            'product_code_id' => $productId,
            'qty_received' => $qty,
            'unit_price' => $unitPrice,
            'amount' => $qty * $unitPrice,
        ];
    }

    if (!$items) {
        $errors[] = 'Cần ít nhất một dòng sản phẩm hợp lệ.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $totalAmount = array_sum(array_column($items, 'amount'));

            if ($isEdit) {
                $stmt = $pdo->prepare('UPDATE job_orders SET job_code = ?, customer_id = ?, received_date = ?, due_date = ?, note = ?, total_amount = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$formData['job_code'], $formData['customer_id'], $formData['received_date'], $formData['due_date'], $formData['note'], $totalAmount, $orderId]);
                $pdo->prepare('DELETE FROM job_order_items WHERE job_order_id = ?')->execute([$orderId]);
                $jobOrderId = $orderId;
            } else {
                $stmt = $pdo->prepare('INSERT INTO job_orders (job_code, customer_id, received_date, due_date, note, status, total_amount, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
                $stmt->execute([$formData['job_code'], $formData['customer_id'], $formData['received_date'], $formData['due_date'], $formData['note'], 'draft', $totalAmount, erp_current_user_id()]);
                $jobOrderId = (int) $pdo->lastInsertId();
            }

            $productMap = [];
            $productStmt = $pdo->query('SELECT id, unit FROM product_codes');
            foreach ($productStmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
                $productMap[(int) $product['id']] = $product;
            }

            $itemInsert = $pdo->prepare('INSERT INTO job_order_items (job_order_id, product_code_id, qty_received, qty_ok, qty_ng, qty_returned, unit_price, amount, created_at, updated_at) VALUES (?, ?, ?, 0, 0, 0, ?, ?, NOW(), NOW())');
            foreach ($items as $item) {
                $itemInsert->execute([$jobOrderId, $item['product_code_id'], $item['qty_received'], $item['unit_price'], $item['amount']]);
            }

            $pdo->commit();
            erp_flash('success', $isEdit ? 'Đã cập nhật phiếu gia công.' : 'Đã tạo phiếu gia công mới.');
            erp_redirect(erp_url('modules/production/job_orders.php'));
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Không thể lưu phiếu gia công: ' . $throwable->getMessage();
        }
    }

    $formData['items'] = [];
    foreach ($items as $item) {
        $formData['items'][] = $item;
    }
}

$customers = $pdo->query('SELECT id, code, name FROM customers WHERE is_active = 1 ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$productCatalog = [];
if ((int) $formData['customer_id'] > 0) {
    $stmt = $pdo->prepare('SELECT p.product_code_id, pc.code, pc.name, pc.unit, p.unit_price FROM prices p INNER JOIN product_codes pc ON pc.id = p.product_code_id WHERE p.customer_id = ? ORDER BY p.effective_date DESC, pc.code ASC');
    $stmt->execute([(int) $formData['customer_id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $productCatalog[(int) $row['product_code_id']] = [
            'product_code_id' => (int) $row['product_code_id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'unit' => $row['unit'],
            'unit_price' => (float) $row['unit_price'],
        ];
    }
}

foreach ($formData['items'] as $item) {
    $productId = (int) ($item['product_code_id'] ?? 0);
    if ($productId > 0 && !isset($productCatalog[$productId])) {
        $productCatalog[$productId] = [
            'product_code_id' => $productId,
            'code' => $item['code'] ?? '',
            'name' => $item['name'] ?? '',
            'unit' => $item['unit'] ?? '',
            'unit_price' => (float) ($item['unit_price'] ?? 0),
        ];
    }
}

$csrfToken = erp_csrf_token();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <?php erp_render_breadcrumb([
        ['label' => 'Tổng quan', 'url' => erp_url('dashboard.php')],
        ['label' => 'Sản xuất', 'url' => erp_url('modules/production/index.php')],
        ['label' => 'Phiếu gia công', 'url' => erp_url('modules/production/job_orders.php')],
        ['label' => $isEdit ? 'Sửa phiếu' : 'Tạo phiếu'],
    ]); ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-1"><?= $isEdit ? 'Sửa phiếu gia công' : 'Tạo phiếu gia công' ?></h1>
            <p class="text-muted mb-0">Lập phiếu nhận hàng gia công, tính đơn giá theo khách hàng và theo dõi giá trị đơn hàng.</p>
        </div>
        <a class="btn btn-outline-secondary" href="<?= erp_h(erp_url('modules/production/job_orders.php')) ?>">Quay lại</a>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= erp_h($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="post" id="jobOrderForm">
        <input type="hidden" name="csrf_token" value="<?= erp_h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= (int) $formData['id'] ?>">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body row g-3">
                <div class="col-md-3">
                    <label class="form-label">Mã phiếu</label>
                    <input type="text" class="form-control" name="job_code" value="<?= erp_h((string) $formData['job_code']) ?>" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Khách hàng</label>
                    <select class="form-select" id="customerSelect" name="customer_id" required>
                        <option value="">Chọn khách hàng</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= (int) $customer['id'] ?>" <?= (int) $formData['customer_id'] === (int) $customer['id'] ? 'selected' : '' ?>><?= erp_h($customer['code'] . ' - ' . $customer['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Ngày nhận</label>
                    <input type="date" class="form-control" name="received_date" value="<?= erp_h((string) $formData['received_date']) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Hạn giao</label>
                    <input type="date" class="form-control" name="due_date" value="<?= erp_h((string) $formData['due_date']) ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label">Ghi chú</label>
                    <textarea class="form-control" name="note" rows="3"><?= erp_h((string) $formData['note']) ?></textarea>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Chi tiết sản phẩm</h2>
                <button type="button" class="btn btn-sm btn-outline-primary" id="addRowBtn"><i class="fa-solid fa-plus me-2"></i>Add row</button>
            </div>
            <div class="card-body table-responsive">
                <table class="table align-middle" id="itemsTable">
                    <thead class="table-light">
                    <tr>
                        <th>Mã SP</th>
                        <th>Tên SP</th>
                        <th>Đơn vị</th>
                        <th>Qty nhận</th>
                        <th>Đơn giá</th>
                        <th>Thành tiền</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot>
                    <tr>
                        <th colspan="5" class="text-end">Tổng cộng</th>
                        <th id="totalAmount">0 đ</th>
                        <th></th>
                    </tr>
                    </tfoot>
                </table>
            </div>
            <div class="card-footer bg-white d-flex justify-content-end gap-2">
                <a class="btn btn-outline-secondary" href="<?= erp_h(erp_url('modules/production/job_orders.php')) ?>">Hủy</a>
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Lưu phiếu gia công</button>
            </div>
        </div>
    </form>
</div>

<script>
const itemsTableBody = document.querySelector('#itemsTable tbody');
const customerSelect = document.getElementById('customerSelect');
let productCatalog = <?= json_encode(array_values($productCatalog), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const initialItems = <?= json_encode($formData['items'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function formatMoney(value) {
    return new Intl.NumberFormat('vi-VN').format(Number(value || 0)) + ' đ';
}

function catalogMap() {
    const map = {};
    productCatalog.forEach(item => {
        map[String(item.product_code_id)] = item;
    });
    return map;
}

function buildOptions(selectedId = '') {
    const selected = String(selectedId || '');
    return ['<option value="">Chọn mã SP</option>']
        .concat(productCatalog.map(item => `<option value="${item.product_code_id}" ${selected === String(item.product_code_id) ? 'selected' : ''}>${item.code} - ${item.name}</option>`))
        .join('');
}

function updateRow(row) {
    const product = catalogMap()[row.querySelector('.product-select').value] || {};
    row.querySelector('.product-name').textContent = product.name || '';
    row.querySelector('.unit-cell').textContent = product.unit || '';
    if (product.unit_price && !row.querySelector('.price-input').dataset.manual) {
        row.querySelector('.price-input').value = product.unit_price;
    }
    const qty = parseFloat(row.querySelector('.qty-input').value || 0);
    const price = parseFloat(row.querySelector('.price-input').value || 0);
    row.querySelector('.amount-cell').textContent = formatMoney(qty * price);
    updateTotal();
}

function updateTotal() {
    let total = 0;
    document.querySelectorAll('#itemsTable tbody tr').forEach(row => {
        const qty = parseFloat(row.querySelector('.qty-input').value || 0);
        const price = parseFloat(row.querySelector('.price-input').value || 0);
        total += qty * price;
    });
    document.getElementById('totalAmount').textContent = formatMoney(total);
}

function addRow(item = {}) {
    const row = document.createElement('tr');
    row.innerHTML = `
        <td><select class="form-select product-select" name="product_code_id[]" required>${buildOptions(item.product_code_id || '')}</select></td>
        <td class="product-name">${item.name || ''}</td>
        <td class="unit-cell">${item.unit || ''}</td>
        <td><input type="number" min="0.01" step="0.01" class="form-control qty-input" name="qty_received[]" value="${item.qty_received || ''}" required></td>
        <td><input type="number" min="0" step="0.01" class="form-control price-input" name="unit_price[]" value="${item.unit_price || ''}" required></td>
        <td class="amount-cell text-nowrap">0 đ</td>
        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="fa-solid fa-trash"></i></button></td>
    `;
    itemsTableBody.appendChild(row);
    row.querySelector('.product-select').addEventListener('change', () => updateRow(row));
    row.querySelector('.qty-input').addEventListener('input', () => updateRow(row));
    row.querySelector('.price-input').addEventListener('input', (event) => {
        event.currentTarget.dataset.manual = '1';
        updateRow(row);
    });
    row.querySelector('.remove-row').addEventListener('click', () => {
        row.remove();
        updateTotal();
    });
    updateRow(row);
}

async function loadPrices(customerId) {
    if (!customerId) {
        productCatalog = [];
        itemsTableBody.innerHTML = '';
        addRow();
        return;
    }
    const response = await fetch(`<?= erp_h(erp_url('api/get_prices.php')) ?>?customer_id=${customerId}`);
    productCatalog = await response.json();
    itemsTableBody.innerHTML = '';
    addRow();
}

document.getElementById('addRowBtn').addEventListener('click', () => addRow());
customerSelect.addEventListener('change', () => loadPrices(customerSelect.value));

if (initialItems.length > 0) {
    initialItems.forEach(item => addRow(item));
} else {
    addRow();
}
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
