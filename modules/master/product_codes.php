<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireLogin();

requireRole('director', 'manager');
$pdo = getDBConnection();

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$formData = [
    'id' => 0,
    'product_code' => '',
    'description' => '',
    'unit' => '',
    'category' => '',
    'is_active' => 1,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc đã hết hạn.');
        header('Location: /ntn_erp/modules/master/product_codes.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_product_code') {
        $formData = [
            'id' => (int) ($_POST['id'] ?? 0),
            'product_code' => trim((string) ($_POST['product_code'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'unit' => trim((string) ($_POST['unit'] ?? '')),
            'category' => trim((string) ($_POST['category'] ?? '')),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        if ($formData['product_code'] === '' || $formData['description'] === '' || $formData['unit'] === '') {
            $errors[] = 'Vui lòng nhập mã sản phẩm, mô tả và đơn vị.';
        }

        $dupStmt = $pdo->prepare('SELECT id FROM product_codes WHERE product_code = ? AND id <> ? LIMIT 1');
        $dupStmt->execute([$formData['product_code'], $formData['id']]);
        if ($dupStmt->fetch()) {
            $errors[] = 'Mã sản phẩm đã tồn tại.';
        }

        if (!$errors) {
            $userId = (int) (currentUser()['id'] ?? 0);
            if ($formData['id'] > 0) {
                $stmt = $pdo->prepare('UPDATE product_codes SET product_code = ?, description = ?, unit = ?, category = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([
                    $formData['product_code'],
                    $formData['description'],
                    $formData['unit'],
                    $formData['category'],
                    $formData['is_active'],
                    $formData['id'],
                ]);
                setFlash('success', 'Đã cập nhật mã sản phẩm.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO product_codes (product_code, description, unit, category, is_active, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())');
                $stmt->execute([
                    $formData['product_code'],
                    $formData['description'],
                    $formData['unit'],
                    $formData['category'],
                    $formData['is_active'],
                    $userId ?: null,
                ]);
                setFlash('success', 'Đã thêm mã sản phẩm.');
            }

            header('Location: /ntn_erp/modules/master/product_codes.php');
            exit;
        }
    }

    if ($action === 'toggle_active') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE product_codes SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
        setFlash('success', 'Đã cập nhật trạng thái mã sản phẩm.');
        header('Location: /ntn_erp/modules/master/product_codes.php');
        exit;
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0 && $formData['id'] === 0) {
    $stmt = $pdo->prepare('SELECT * FROM product_codes WHERE id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($record) {
        $formData = array_merge($formData, $record, ['id' => (int) $record['id']]);
    }
}

$productCodes = $pdo->query('SELECT id, product_code, description, unit, category, is_active FROM product_codes ORDER BY product_code ASC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
$flash = getFlash();
$csrfToken = generateCSRF();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Danh mục mã sản phẩm</h1>
            <p class="text-muted mb-0">Quản lý mã sản phẩm, mô tả, đơn vị tính và nhóm hàng.</p>
        </div>
        <a href="/ntn_erp/modules/master/product_codes.php" class="btn btn-outline-secondary">Làm mới</a>
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
                                <th>Đơn vị</th>
                                <th>Nhóm hàng</th>
                                <th>Trạng thái</th>
                                <th class="text-end">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$productCodes): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Chưa có mã sản phẩm.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($productCodes as $item): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($item['product_code']) ?></td>
                                    <td><?= e($item['description']) ?></td>
                                    <td><?= e($item['unit']) ?></td>
                                    <td><?= e($item['category']) ?></td>
                                    <td><span class="badge text-bg-<?= (int) $item['is_active'] === 1 ? 'success' : 'secondary' ?>"><?= (int) $item['is_active'] === 1 ? 'Đang dùng' : 'Ngưng dùng' ?></span></td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <a href="/ntn_erp/modules/master/product_codes.php?edit=<?= (int) $item['id'] ?>" class="btn btn-outline-primary">Sửa</a>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                                <button type="submit" class="btn btn-outline-secondary">Bật/Tắt</button>
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

        <div class="col-lg-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    <h2 class="h5 mb-0"><?= $formData['id'] > 0 ? 'Cập nhật mã sản phẩm' : 'Thêm mã sản phẩm' ?></h2>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="save_product_code">
                        <input type="hidden" name="id" value="<?= (int) $formData['id'] ?>">
                        <div class="col-12">
                            <label class="form-label">Mã sản phẩm <span class="text-danger">*</span></label>
                            <input type="text" name="product_code" class="form-control" value="<?= e($formData['product_code']) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Mô tả <span class="text-danger">*</span></label>
                            <textarea name="description" class="form-control" rows="3" required><?= e($formData['description']) ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Đơn vị <span class="text-danger">*</span></label>
                            <input type="text" name="unit" class="form-control" value="<?= e($formData['unit']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nhóm hàng</label>
                            <input type="text" name="category" class="form-control" value="<?= e($formData['category']) ?>">
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="productActive" name="is_active" <?= (int) $formData['is_active'] === 1 ? 'checked' : '' ?>>
                                <label class="form-check-label" for="productActive">Đang sử dụng</label>
                            </div>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-fill">Lưu mã sản phẩm</button>
                            <a href="/ntn_erp/modules/master/product_codes.php" class="btn btn-outline-secondary">Hủy</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
