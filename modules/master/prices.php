<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireLogin();

requireRole('director', 'accountant', 'manager');
$pdo = getDBConnection();

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('formatCurrency')) {
    function formatCurrency($amount): string
    {
        return number_format((float) $amount, 0, ',', '.') . ' đ';
    }
}

$formData = [
    'id' => 0,
    'product_code_id' => 0,
    'unit_price' => '',
    'effective_from' => date('Y-m-d'),
    'note' => '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc đã hết hạn.');
        header('Location: /ntn_erp/modules/master/prices.php');
        exit;
    }

    if (($_POST['action'] ?? '') === 'save_price') {
        $formData = [
            'id' => (int) ($_POST['id'] ?? 0),
            'product_code_id' => (int) ($_POST['product_code_id'] ?? 0),
            'unit_price' => trim((string) ($_POST['unit_price'] ?? '')),
            'effective_from' => trim((string) ($_POST['effective_from'] ?? date('Y-m-d'))),
            'note' => trim((string) ($_POST['note'] ?? '')),
        ];

        $unitPrice = (float) str_replace([',', ' '], ['', ''], $formData['unit_price']);
        if ($formData['product_code_id'] <= 0 || $unitPrice <= 0 || $formData['effective_from'] === '') {
            $errors[] = 'Vui lòng chọn sản phẩm, đơn giá và ngày áp dụng.';
        }

        if (!$errors) {
            $userId = (int) (currentUser()['id'] ?? 0);
            if ($formData['id'] > 0) {
                $stmt = $pdo->prepare('UPDATE product_prices SET product_code_id = ?, unit_price = ?, effective_from = ?, note = ? WHERE id = ?');
                $stmt->execute([
                    $formData['product_code_id'],
                    $unitPrice,
                    $formData['effective_from'],
                    $formData['note'],
                    $formData['id'],
                ]);
                setFlash('success', 'Đã cập nhật đơn giá.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO product_prices (product_code_id, unit_price, effective_from, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
                $stmt->execute([
                    $formData['product_code_id'],
                    $unitPrice,
                    $formData['effective_from'],
                    $formData['note'],
                    $userId ?: null,
                ]);
                setFlash('success', 'Đã thêm đơn giá mới.');
            }
            header('Location: /ntn_erp/modules/master/prices.php');
            exit;
        }
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0 && $formData['id'] === 0) {
    $stmt = $pdo->prepare('SELECT * FROM product_prices WHERE id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($record) {
        $formData = array_merge($formData, $record, ['id' => (int) $record['id']]);
    }
}

$productCodes = $pdo->query('SELECT id, product_code, description, unit FROM product_codes WHERE is_active = 1 ORDER BY product_code ASC')->fetchAll(PDO::FETCH_ASSOC);
$prices = $pdo->query('SELECT pp.*, pc.product_code, pc.description, pc.unit FROM product_prices pp INNER JOIN product_codes pc ON pc.id = pp.product_code_id ORDER BY pp.effective_from DESC, pc.product_code ASC, pp.id DESC')->fetchAll(PDO::FETCH_ASSOC);
$flash = getFlash();
$csrfToken = generateCSRF();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Bảng giá sản phẩm</h1>
            <p class="text-muted mb-0">Quản lý giá bán theo mã sản phẩm và ngày hiệu lực.</p>
        </div>
        <a href="/ntn_erp/modules/master/prices.php" class="btn btn-outline-secondary">Làm mới</a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= e($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-body table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Mã sản phẩm</th>
                                <th>Mô tả</th>
                                <th>ĐVT</th>
                                <th>Đơn giá</th>
                                <th>Hiệu lực từ</th>
                                <th>Ghi chú</th>
                                <th class="text-end">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$prices): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">Chưa có bảng giá.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($prices as $price): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($price['product_code']) ?></td>
                                    <td><?= e($price['description']) ?></td>
                                    <td><?= e($price['unit']) ?></td>
                                    <td><?= e(formatCurrency($price['unit_price'])) ?></td>
                                    <td><?= e(date('d/m/Y', strtotime($price['effective_from']))) ?></td>
                                    <td><?= e($price['note']) ?></td>
                                    <td class="text-end"><a href="/ntn_erp/modules/master/prices.php?edit=<?= (int) $price['id'] ?>" class="btn btn-sm btn-outline-primary">Sửa</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    <h2 class="h5 mb-0"><?= $formData['id'] > 0 ? 'Cập nhật giá' : 'Thêm giá mới' ?></h2>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="save_price">
                        <input type="hidden" name="id" value="<?= (int) $formData['id'] ?>">
                        <div class="col-12">
                            <label class="form-label">Sản phẩm <span class="text-danger">*</span></label>
                            <select name="product_code_id" class="form-select" required>
                                <option value="">Chọn sản phẩm</option>
                                <?php foreach ($productCodes as $product): ?>
                                    <option value="<?= (int) $product['id'] ?>" <?= (int) $formData['product_code_id'] === (int) $product['id'] ? 'selected' : '' ?>><?= e($product['product_code'] . ' - ' . $product['description']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Đơn giá <span class="text-danger">*</span></label>
                            <input type="number" min="0" step="0.01" name="unit_price" class="form-control" value="<?= e((string) $formData['unit_price']) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Hiệu lực từ <span class="text-danger">*</span></label>
                            <input type="date" name="effective_from" class="form-control" value="<?= e($formData['effective_from']) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Ghi chú</label>
                            <textarea name="note" class="form-control" rows="3"><?= e($formData['note']) ?></textarea>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-fill">Lưu đơn giá</button>
                            <a href="/ntn_erp/modules/master/prices.php" class="btn btn-outline-secondary">Hủy</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
