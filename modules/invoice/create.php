<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

requireRole('director', 'accountant');
$pdo = erp_db();
$errors = [];

// Danh sách khách hàng có phiếu giao hàng chưa lập hóa đơn
$customers = $pdo->query("
    SELECT DISTINCT c.id, c.code, c.name
    FROM customers c
    INNER JOIN deliveries d ON d.customer_id = c.id
    LEFT JOIN invoice_deliveries idl ON idl.delivery_id = d.id
    WHERE idl.id IS NULL
    ORDER BY c.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Phiếu giao hàng chưa lập hóa đơn, nhóm theo khách hàng
$allDeliveries = $pdo->query("
    SELECT d.id, d.delivery_code, d.delivery_date, d.customer_id,
           (SELECT COALESCE(SUM(di.amount), 0) FROM delivery_items di WHERE di.delivery_id = d.id) AS subtotal
    FROM deliveries d
    LEFT JOIN invoice_deliveries idl ON idl.delivery_id = d.id
    WHERE idl.id IS NULL
    ORDER BY d.delivery_date DESC, d.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$deliveriesByCustomer = [];
foreach ($allDeliveries as $d) {
    $deliveriesByCustomer[(int) $d['customer_id']][] = $d;
}

// Delivery items nhóm theo delivery_id (cho preview)
$deliveryItemMap = [];
if ($allDeliveries) {
    $dlIds = array_column($allDeliveries, 'id');
    $ph = implode(',', array_fill(0, count($dlIds), '?'));
    $itemRows = $pdo->prepare("
        SELECT di.delivery_id, di.qty_delivered, di.unit_price, di.amount,
               pc.code, pc.name, pc.unit
        FROM delivery_items di
        INNER JOIN product_codes pc ON pc.id = di.product_code_id
        WHERE di.delivery_id IN ($ph)
        ORDER BY di.delivery_id ASC, di.id ASC
    ");
    $itemRows->execute($dlIds);
    foreach ($itemRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $deliveryItemMap[(int) $row['delivery_id']][] = $row;
    }
}

$formData = [
    'invoice_code' => erp_generate_daily_code($pdo, 'invoices', 'invoice_code', 'INV'),
    'customer_id'  => 0,
    'invoice_date' => date('Y-m-d'),
    'due_date'     => date('Y-m-d', strtotime('+30 days')),
    'tax_rate'     => 8,
    'note'         => '',
    'delivery_ids' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['invoice_code'] = trim((string) ($_POST['invoice_code'] ?? $formData['invoice_code']));
    $formData['customer_id']  = (int) ($_POST['customer_id'] ?? 0);
    $formData['invoice_date'] = (string) ($_POST['invoice_date'] ?? date('Y-m-d'));
    $formData['due_date']     = (string) ($_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days')));
    $formData['tax_rate']     = (int) ($_POST['tax_rate'] ?? 8);
    $formData['note']         = trim((string) ($_POST['note'] ?? ''));
    $formData['delivery_ids'] = array_map('intval', (array) ($_POST['delivery_ids'] ?? []));
    $formData['delivery_ids'] = array_values(array_filter($formData['delivery_ids']));

    if (!erp_validate_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'CSRF token không hợp lệ.';
    }
    if ($formData['customer_id'] <= 0) {
        $errors[] = 'Vui lòng chọn khách hàng.';
    }
    if (empty($formData['delivery_ids'])) {
        $errors[] = 'Vui lòng chọn ít nhất một phiếu giao hàng.';
    }
    if ($formData['invoice_date'] === '' || $formData['due_date'] === '') {
        $errors[] = 'Vui lòng nhập ngày hóa đơn và hạn thanh toán.';
    }
    if (!in_array($formData['tax_rate'], [0, 8], true)) {
        $errors[] = 'Thuế suất chỉ được phép là 0% hoặc 8%.';
    }

    if (!$errors) {
        // Xác nhận các phiếu giao thuộc khách hàng và chưa có hóa đơn
        $ph = implode(',', array_fill(0, count($formData['delivery_ids']), '?'));
        $validStmt = $pdo->prepare("
            SELECT d.id FROM deliveries d
            LEFT JOIN invoice_deliveries idl ON idl.delivery_id = d.id
            WHERE d.id IN ($ph) AND d.customer_id = ? AND idl.id IS NULL
        ");
        $validStmt->execute([...$formData['delivery_ids'], $formData['customer_id']]);
        $validIds = array_column($validStmt->fetchAll(PDO::FETCH_ASSOC), 'id');
        if (count($validIds) !== count($formData['delivery_ids'])) {
            $errors[] = 'Một hoặc nhiều phiếu giao hàng không hợp lệ hoặc đã được lập hóa đơn.';
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $ph = implode(',', array_fill(0, count($formData['delivery_ids']), '?'));
            $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(di.amount),0) FROM delivery_items di WHERE di.delivery_id IN ($ph)");
            $sumStmt->execute($formData['delivery_ids']);
            $subtotal    = (float) $sumStmt->fetchColumn();
            $taxAmount   = round($subtotal * $formData['tax_rate'] / 100, 2);
            $totalAmount = $subtotal + $taxAmount;
            $status      = 'unpaid';

            $backCompatId = count($formData['delivery_ids']) === 1 ? $formData['delivery_ids'][0] : null;

            $insStmt = $pdo->prepare('INSERT INTO invoices (invoice_code, delivery_id, customer_id, invoice_date, due_date, subtotal, tax_rate, tax_amount, total_amount, paid_amount, status, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)');
            $insStmt->execute([
                $formData['invoice_code'], $backCompatId, $formData['customer_id'],
                $formData['invoice_date'], $formData['due_date'],
                $subtotal, $formData['tax_rate'], $taxAmount, $totalAmount,
                $status, $formData['note'] ?: null,
                erp_current_user_id() ?: null,
            ]);
            $invoiceId = (int) $pdo->lastInsertId();

            $insIdl = $pdo->prepare('INSERT INTO invoice_deliveries (invoice_id, delivery_id) VALUES (?, ?)');
            foreach ($formData['delivery_ids'] as $dlId) {
                $insIdl->execute([$invoiceId, $dlId]);
            }

            $pdo->commit();
            erp_flash('success', 'Đã tạo hóa đơn ' . $formData['invoice_code'] . '.');
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
$flashes = erp_pull_flashes();
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <?php erp_render_breadcrumb([
        ['label' => 'Tổng quan', 'url' => erp_url('dashboard.php')],
        ['label' => 'Hóa đơn', 'url' => erp_url('modules/invoice/index.php')],
        ['label' => 'Tạo hóa đơn'],
    ]); ?>

    <div class="mb-3">
        <h1 class="h3 mb-1">Tạo hóa đơn</h1>
        <p class="text-muted mb-0">Gom một hoặc nhiều phiếu giao hàng thành một hóa đơn.</p>
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

    <form method="post" id="invoiceForm">
        <input type="hidden" name="csrf_token" value="<?= erp_h($csrfToken) ?>">

        <!-- Thông tin hóa đơn -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white"><h2 class="h5 mb-0">Thông tin hóa đơn</h2></div>
            <div class="card-body row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Mã hóa đơn</label>
                    <input type="text" class="form-control" name="invoice_code" value="<?= erp_h($formData['invoice_code']) ?>" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Khách hàng <span class="text-danger">*</span></label>
                    <select class="form-select" name="customer_id" id="customerSelect" required>
                        <option value="">Chọn khách hàng</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= (int) $customer['id'] ?>" <?= $formData['customer_id'] === (int) $customer['id'] ? 'selected' : '' ?>>
                                <?= erp_h($customer['code'] . ' - ' . $customer['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Ngày HĐ <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="invoice_date" value="<?= erp_h($formData['invoice_date']) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Hạn TT <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="due_date" value="<?= erp_h($formData['due_date']) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Thuế suất <span class="text-danger">*</span></label>
                    <select class="form-select" name="tax_rate" id="taxRateSelect">
                        <option value="0" <?= $formData['tax_rate'] === 0 ? 'selected' : '' ?>>0%</option>
                        <option value="8" <?= $formData['tax_rate'] === 8 ? 'selected' : '' ?>>8%</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Ghi chú</label>
                    <input type="text" class="form-control" name="note" value="<?= erp_h($formData['note']) ?>" placeholder="Tuỳ chọn">
                </div>
            </div>
        </div>

        <!-- Chọn phiếu giao hàng -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white"><h2 class="h5 mb-0">Chọn phiếu giao hàng</h2></div>
            <div class="card-body">
                <div id="deliveryList" class="row g-2">
                    <p class="text-muted">Chọn khách hàng để hiển thị danh sách phiếu giao chưa lập hóa đơn.</p>
                </div>
            </div>
        </div>

        <!-- Chi tiết sản phẩm (preview tổng hợp) -->
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><h2 class="h5 mb-0">Chi tiết tổng hợp</h2></div>
            <div class="card-body table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Phiếu GH</th><th>Mã SP</th><th>Tên SP</th><th>ĐVT</th><th class="text-end">SL</th><th class="text-end">Đơn giá</th><th class="text-end">Thành tiền</th></tr>
                    </thead>
                    <tbody id="invoiceItemsBody">
                        <tr><td colspan="7" class="text-center text-muted py-3">Chọn phiếu giao hàng để xem chi tiết.</td></tr>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="6" class="text-end fw-semibold">Subtotal</td><td class="text-end fw-semibold" id="subtotalCell">—</td></tr>
                        <tr><td colspan="6" class="text-end fw-semibold" id="taxLabel">Thuế (8%)</td><td class="text-end fw-semibold" id="taxAmountCell">—</td></tr>
                        <tr class="table-success"><td colspan="6" class="text-end fw-bold">Tổng cộng</td><td class="text-end fw-bold" id="totalAmountCell">—</td></tr>
                    </tfoot>
                </table>
            </div>
            <div class="card-footer bg-white d-flex justify-content-end gap-2">
                <a class="btn btn-outline-secondary" href="<?= erp_h(erp_url('modules/invoice/index.php')) ?>">Hủy</a>
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Lưu hóa đơn</button>
            </div>
        </div>
    </form>
</div>

<script>
const deliveriesByCustomer = <?= json_encode($deliveriesByCustomer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
const deliveryItemMap      = <?= json_encode($deliveryItemMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;

function formatVnd(v) { return new Intl.NumberFormat('vi-VN').format(Math.round(Number(v)||0)) + ' đ'; }

function renderDeliveryList(customerId) {
    const container = document.getElementById('deliveryList');
    const deliveries = deliveriesByCustomer[customerId] || [];
    if (!deliveries.length) {
        container.innerHTML = '<p class="text-muted">Không có phiếu giao hàng chưa lập hóa đơn cho khách hàng này.</p>';
        return;
    }
    container.innerHTML = deliveries.map(d => `
        <div class="col-md-4 col-lg-3">
            <label class="card border rounded p-2 cursor-pointer w-100 delivery-card" data-id="${d.id}">
                <div class="d-flex align-items-center gap-2">
                    <input type="checkbox" name="delivery_ids[]" value="${d.id}" class="form-check-input delivery-check" style="flex-shrink:0">
                    <div>
                        <div class="fw-semibold text-primary small">${d.delivery_code}</div>
                        <div class="text-muted" style="font-size:11px">${d.delivery_date} &nbsp;|&nbsp; ${formatVnd(d.subtotal)}</div>
                    </div>
                </div>
            </label>
        </div>`).join('');

    document.querySelectorAll('.delivery-check').forEach(cb => {
        cb.addEventListener('change', renderInvoiceItems);
    });
}

function renderInvoiceItems() {
    const checked = [...document.querySelectorAll('.delivery-check:checked')].map(cb => cb.value);
    const tbody = document.getElementById('invoiceItemsBody');
    const taxRate = Number(document.getElementById('taxRateSelect').value || 0);
    document.getElementById('taxLabel').textContent = 'Thuế (' + taxRate + '%)';

    if (!checked.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">Chọn phiếu giao hàng để xem chi tiết.</td></tr>';
        ['subtotalCell','taxAmountCell','totalAmountCell'].forEach(id => document.getElementById(id).textContent = '—');
        return;
    }

    let rows = '';
    let subtotal = 0;
    checked.forEach(dlId => {
        const items = deliveryItemMap[dlId] || [];
        items.forEach(item => {
            subtotal += Number(item.amount || 0);
            rows += `<tr>
                <td><small class="text-muted">${dlId}</small></td>
                <td><code>${item.code}</code></td>
                <td>${item.name}</td>
                <td>${item.unit}</td>
                <td class="text-end">${item.qty_delivered}</td>
                <td class="text-end">${formatVnd(item.unit_price)}</td>
                <td class="text-end">${formatVnd(item.amount)}</td>
            </tr>`;
        });
    });
    tbody.innerHTML = rows || '<tr><td colspan="7" class="text-center text-muted py-3">Các phiếu giao hàng chưa có sản phẩm.</td></tr>';

    const taxAmount = subtotal * taxRate / 100;
    document.getElementById('subtotalCell').textContent = formatVnd(subtotal);
    document.getElementById('taxAmountCell').textContent = formatVnd(taxAmount);
    document.getElementById('totalAmountCell').textContent = formatVnd(subtotal + taxAmount);
}

document.getElementById('customerSelect').addEventListener('change', function () {
    renderDeliveryList(this.value);
    renderInvoiceItems();
});

document.getElementById('taxRateSelect').addEventListener('change', renderInvoiceItems);

// Init if customer pre-selected (on validation fail with POST)
<?php if ($formData['customer_id'] > 0): ?>
renderDeliveryList(<?= (int) $formData['customer_id'] ?>);
<?php if (!empty($formData['delivery_ids'])): ?>
setTimeout(() => {
    const preSelected = <?= json_encode($formData['delivery_ids'], JSON_HEX_TAG) ?>;
    document.querySelectorAll('.delivery-check').forEach(cb => {
        if (preSelected.includes(Number(cb.value))) { cb.checked = true; }
    });
    renderInvoiceItems();
}, 0);
<?php endif; ?>
<?php endif; ?>
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>

