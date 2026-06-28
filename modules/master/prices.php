<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

requireRole(['director', 'accountant', 'manager']);
$pdo = erp_db();
$errors = [];
$formData = [
    'id' => 0,
    'customer_id' => 0,
    'product_code_id' => 0,
    'unit_price' => '',
    'effective_date' => date('Y-m-d'),
    'note' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!erp_validate_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Phiên làm việc hết hạn. Vui lòng thử lại.';
    } else {
        try {
            if ($action === 'save_price') {
                $formData = [
                    'id' => (int) ($_POST['id'] ?? 0),
                    'customer_id' => (int) ($_POST['customer_id'] ?? 0),
                    'product_code_id' => (int) ($_POST['product_code_id'] ?? 0),
                    'unit_price' => (string) ($_POST['unit_price'] ?? ''),
                    'effective_date' => (string) ($_POST['effective_date'] ?? date('Y-m-d')),
                    'note' => trim((string) ($_POST['note'] ?? '')),
                ];
                $unitPrice = erp_to_decimal($formData['unit_price']);

                if ($formData['customer_id'] <= 0 || $formData['product_code_id'] <= 0 || $unitPrice <= 0) {
                    $errors[] = 'Khách hàng, mã sản phẩm và đơn giá là bắt buộc.';
                }

                if (!$errors) {
                    if ($formData['id'] > 0) {
                        $stmt = $pdo->prepare('UPDATE prices SET customer_id = ?, product_code_id = ?, unit_price = ?, effective_date = ?, note = ?, updated_at = NOW() WHERE id = ?');
                        $stmt->execute([$formData['customer_id'], $formData['product_code_id'], $unitPrice, $formData['effective_date'], $formData['note'], $formData['id']]);
                        erp_flash('success', 'Đã cập nhật bảng giá.');
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO prices (customer_id, product_code_id, unit_price, effective_date, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
                        $stmt->execute([$formData['customer_id'], $formData['product_code_id'], $unitPrice, $formData['effective_date'], $formData['note']]);
                        erp_flash('success', 'Đã thêm giá sản phẩm.');
                    }
                    erp_redirect(erp_url('modules/master/prices.php'));
                }
            }

            if ($action === 'delete_price') {
                $stmt = $pdo->prepare('DELETE FROM prices WHERE id = ?');
                $stmt->execute([(int) ($_POST['price_id'] ?? 0)]);
                erp_flash('success', 'Đã xóa dòng giá.');
                erp_redirect(erp_url('modules/master/prices.php'));
            }
        } catch (PDOException $exception) {
            $errors[] = 'Không thể lưu bảng giá: ' . $exception->getMessage();
        }
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0 && $formData['id'] === 0) {
    $stmt = $pdo->prepare('SELECT * FROM prices WHERE id = ?');
    $stmt->execute([$editId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($record) {
        $formData = array_merge($formData, $record, ['id' => (int) $record['id']]);
    }
}

$filterCustomerId = (int) ($_GET['customer_id'] ?? 0);
$customers = $pdo->query('SELECT id, code, name FROM customers WHERE is_active = 1 ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$productCodes = $pdo->query('SELECT id, code, name, unit FROM product_codes WHERE is_active = 1 ORDER BY code ASC')->fetchAll(PDO::FETCH_ASSOC);

$sql = 'SELECT p.*, c.name AS customer_name, c.code AS customer_code, pc.code AS product_code, pc.name AS product_name, pc.unit
        FROM prices p
        INNER JOIN customers c ON c.id = p.customer_id
        INNER JOIN product_codes pc ON pc.id = p.product_code_id
        WHERE 1=1';
$params = [];
if ($filterCustomerId > 0) {
    $sql .= ' AND p.customer_id = ?';
    $params[] = $filterCustomerId;
}
$sql .= ' ORDER BY c.name ASC, p.effective_date DESC, pc.code ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$prices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = erp_csrf_token();
$flashes = erp_pull_flashes();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <?php erp_render_breadcrumb([
        ['label' => 'Tổng quan', 'url' => erp_url('dashboard.php')],
        ['label' => 'Sản xuất', 'url' => erp_url('modules/production/index.php')],
        ['label' => 'Bảng giá'],
    ]); ?>

    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h3 mb-1">Bảng giá khách hàng</h1>
            <p class="text-muted mb-0">Quản lý đơn giá theo từng khách hàng và mã sản phẩm.</p>
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
        <div class="col-xl-8">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <form class="row g-3 mb-3" method="get">
                        <div class="col-md-6">
                            <label class="form-label">Khách hàng</label>
                            <select class="form-select" name="customer_id">
                                <option value="0">Tất cả khách hàng</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?= (int) $customer['id'] ?>" <?= $filterCustomerId === (int) $customer['id'] ? 'selected' : '' ?>><?= erp_h($customer['code'] . ' - ' . $customer['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 align-self-end">
                            <button class="btn btn-outline-primary" type="submit"><i class="fa-solid fa-filter me-2"></i>Lọc</button>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>KH</th>
                                <th>Mã sản phẩm</th>
                                <th>Tên SP</th>
                                <th>Đơn vị</th>
                                <th>Đơn giá</th>
                                <th>Ngày áp dụng</th>
                                <th>Note</th>
                                <th class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$prices): ?>
                                <tr><td colspan="8" class="text-center text-muted py-4">Chưa có dữ liệu bảng giá.</td></tr>
                            <?php endif; ?>
                            <?php $currentCustomer = null; ?>
                            <?php foreach ($prices as $price): ?>
                                <?php if ($currentCustomer !== $price['customer_name']): ?>
                                    <tr class="table-secondary">
                                        <td colspan="8" class="fw-semibold"><?= erp_h($price['customer_code'] . ' - ' . $price['customer_name']) ?></td>
                                    </tr>
                                    <?php $currentCustomer = $price['customer_name']; ?>
                                <?php endif; ?>
                                <tr>
                                    <td><?= erp_h($price['customer_name']) ?></td>
                                    <td><?= erp_h($price['product_code']) ?></td>
                                    <td><?= erp_h($price['product_name']) ?></td>
                                    <td><?= erp_h($price['unit']) ?></td>
                                    <td class="fw-semibold text-nowrap"><?= erp_h(erp_format_vnd($price['unit_price'])) ?></td>
                                    <td><?= erp_h(erp_format_date($price['effective_date'])) ?></td>
                                    <td><?= erp_h($price['note']) ?></td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <a class="btn btn-outline-primary" href="<?= erp_h(erp_url('modules/master/prices.php?edit=' . (int) $price['id'])) ?>"><i class="fa-regular fa-pen-to-square"></i></a>
                                            <form method="post" onsubmit="return confirm('Xóa dòng giá này?');">
                                                <input type="hidden" name="csrf_token" value="<?= erp_h($csrfToken) ?>">
                                                <input type="hidden" name="action" value="delete_price">
                                                <input type="hidden" name="price_id" value="<?= (int) $price['id'] ?>">
                                                <button class="btn btn-outline-danger" type="submit"><i class="fa-regular fa-trash-can"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    <h2 class="h5 mb-0"><?= (int) $formData['id'] > 0 ? 'Cập nhật giá' : 'Thêm giá mới' ?></h2>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= erp_h($csrfToken) ?>">
                        <input type="hidden" name="action" value="save_price">
                        <input type="hidden" name="id" value="<?= (int) $formData['id'] ?>">
                        <div class="col-12">
                            <label class="form-label">Khách hàng</label>
                            <select class="form-select" name="customer_id" required>
                                <option value="">Chọn khách hàng</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?= (int) $customer['id'] ?>" <?= (int) $formData['customer_id'] === (int) $customer['id'] ? 'selected' : '' ?>><?= erp_h($customer['code'] . ' - ' . $customer['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Mã sản phẩm</label>
                            <select class="form-select" name="product_code_id" required>
                                <option value="">Chọn mã sản phẩm</option>
                                <?php foreach ($productCodes as $productCode): ?>
                                    <option value="<?= (int) $productCode['id'] ?>" <?= (int) $formData['product_code_id'] === (int) $productCode['id'] ? 'selected' : '' ?>><?= erp_h($productCode['code'] . ' - ' . $productCode['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Đơn giá (VND)</label>
                            <input type="number" min="0" step="0.01" class="form-control" name="unit_price" value="<?= erp_h((string) $formData['unit_price']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ngày áp dụng</label>
                            <input type="date" class="form-control" name="effective_date" value="<?= erp_h((string) $formData['effective_date']) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Ghi chú</label>
                            <textarea class="form-control" name="note" rows="3"><?= erp_h($formData['note']) ?></textarea>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Lưu bảng giá</button>
                            <a class="btn btn-outline-secondary" href="<?= erp_h(erp_url('modules/master/prices.php')) ?>">Làm mới</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
