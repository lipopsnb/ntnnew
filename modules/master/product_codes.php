<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

requireRole(['director', 'manager']);
$pdo = erp_db();
$errors = [];
$openModal = false;
$formData = [
    'id' => 0,
    'code' => '',
    'name' => '',
    'unit' => '',
    'description' => '',
    'is_active' => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!erp_validate_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Phiên làm việc hết hạn. Vui lòng thử lại.';
        $openModal = true;
    } else {
        try {
            if ($action === 'save_product_code') {
                $openModal = true;
                $formData = [
                    'id' => (int) ($_POST['id'] ?? 0),
                    'code' => trim((string) ($_POST['code'] ?? '')),
                    'name' => trim((string) ($_POST['name'] ?? '')),
                    'unit' => trim((string) ($_POST['unit'] ?? '')),
                    'description' => trim((string) ($_POST['description'] ?? '')),
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                ];

                if ($formData['code'] === '' || $formData['name'] === '' || $formData['unit'] === '') {
                    $errors[] = 'Mã, tên và đơn vị là bắt buộc.';
                }

                if (!$errors) {
                    if ($formData['id'] > 0) {
                        $stmt = $pdo->prepare('UPDATE product_codes SET code = ?, name = ?, unit = ?, description = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
                        $stmt->execute([
                            $formData['code'],
                            $formData['name'],
                            $formData['unit'],
                            $formData['description'],
                            $formData['is_active'],
                            $formData['id'],
                        ]);
                        erp_flash('success', 'Đã cập nhật mã sản phẩm.');
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO product_codes (code, name, unit, description, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
                        $stmt->execute([
                            $formData['code'],
                            $formData['name'],
                            $formData['unit'],
                            $formData['description'],
                            $formData['is_active'],
                        ]);
                        erp_flash('success', 'Đã thêm mã sản phẩm.');
                    }

                    erp_redirect(erp_url('modules/master/product_codes.php'));
                }
            }

            if ($action === 'delete_product_code') {
                $stmt = $pdo->prepare('DELETE FROM product_codes WHERE id = ?');
                $stmt->execute([(int) ($_POST['product_code_id'] ?? 0)]);
                erp_flash('success', 'Đã xóa mã sản phẩm.');
                erp_redirect(erp_url('modules/master/product_codes.php'));
            }
        } catch (PDOException $exception) {
            $errors[] = 'Không thể lưu mã sản phẩm: ' . $exception->getMessage();
            $openModal = true;
        }
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0 && $formData['id'] === 0) {
    $stmt = $pdo->prepare('SELECT * FROM product_codes WHERE id = ?');
    $stmt->execute([$editId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($record) {
        $formData = array_merge($formData, $record, ['id' => (int) $record['id']]);
        $openModal = true;
    }
}

$productCodes = $pdo->query('SELECT * FROM product_codes ORDER BY code ASC')->fetchAll(PDO::FETCH_ASSOC);
$csrfToken = erp_csrf_token();
$flashes = erp_pull_flashes();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <?php erp_render_breadcrumb([
        ['label' => 'Tổng quan', 'url' => erp_url('dashboard.php')],
        ['label' => 'Sản xuất', 'url' => erp_url('modules/production/index.php')],
        ['label' => 'Mã sản phẩm'],
    ]); ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-1">Quản lý mã sản phẩm</h1>
            <p class="text-muted mb-0">Quản trị danh mục mã sản phẩm gia công và đơn vị tính.</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productCodeModal"><i class="fa-solid fa-plus me-2"></i>Thêm mã sản phẩm</button>
    </div>

    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= erp_h($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= erp_h($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= erp_h($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Mã SP</th>
                    <th>Tên sản phẩm</th>
                    <th>Đơn vị</th>
                    <th>Mô tả</th>
                    <th>Trạng thái</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$productCodes): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Chưa có mã sản phẩm.</td></tr>
                <?php endif; ?>
                <?php foreach ($productCodes as $productCode): ?>
                    <tr>
                        <td class="fw-semibold"><?= erp_h($productCode['code']) ?></td>
                        <td><?= erp_h($productCode['name']) ?></td>
                        <td><?= erp_h($productCode['unit']) ?></td>
                        <td><?= erp_h($productCode['description']) ?></td>
                        <td><span class="badge text-bg-<?= (int) $productCode['is_active'] === 1 ? 'success' : 'secondary' ?>"><?= (int) $productCode['is_active'] === 1 ? 'Đang dùng' : 'Ngưng dùng' ?></span></td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <button
                                    type="button"
                                    class="btn btn-outline-primary edit-product-code"
                                    data-id="<?= (int) $productCode['id'] ?>"
                                    data-code="<?= erp_h($productCode['code']) ?>"
                                    data-name="<?= erp_h($productCode['name']) ?>"
                                    data-unit="<?= erp_h($productCode['unit']) ?>"
                                    data-description="<?= erp_h($productCode['description']) ?>"
                                    data-is-active="<?= (int) $productCode['is_active'] ?>"
                                    data-bs-toggle="modal"
                                    data-bs-target="#productCodeModal"
                                ><i class="fa-regular fa-pen-to-square"></i></button>
                                <form method="post" onsubmit="return confirm('Xóa mã sản phẩm này?');">
                                    <input type="hidden" name="csrf_token" value="<?= erp_h($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_product_code">
                                    <input type="hidden" name="product_code_id" value="<?= (int) $productCode['id'] ?>">
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

<div class="modal fade" id="productCodeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title"><?= (int) $formData['id'] > 0 ? 'Cập nhật mã sản phẩm' : 'Thêm mã sản phẩm' ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <input type="hidden" name="csrf_token" value="<?= erp_h($csrfToken) ?>">
                    <input type="hidden" name="action" value="save_product_code">
                    <input type="hidden" name="id" id="modal-id" value="<?= (int) $formData['id'] ?>">
                    <div class="col-md-4">
                        <label class="form-label">Mã sản phẩm</label>
                        <input type="text" class="form-control" name="code" id="modal-code" value="<?= erp_h($formData['code']) ?>" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Tên sản phẩm</label>
                        <input type="text" class="form-control" name="name" id="modal-name" value="<?= erp_h($formData['name']) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Đơn vị</label>
                        <input type="text" class="form-control" name="unit" id="modal-unit" value="<?= erp_h($formData['unit']) ?>" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Mô tả</label>
                        <textarea class="form-control" name="description" id="modal-description" rows="3"><?= erp_h($formData['description']) ?></textarea>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="modal-active" name="is_active" <?= (int) $formData['is_active'] === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="modal-active">Đang sử dụng</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Đóng</button>
                    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Lưu</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('productCodeModal');
    const modal = new bootstrap.Modal(modalElement);
    document.querySelectorAll('.edit-product-code').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('modal-id').value = button.dataset.id || '0';
            document.getElementById('modal-code').value = button.dataset.code || '';
            document.getElementById('modal-name').value = button.dataset.name || '';
            document.getElementById('modal-unit').value = button.dataset.unit || '';
            document.getElementById('modal-description').value = button.dataset.description || '';
            document.getElementById('modal-active').checked = button.dataset.isActive === '1';
        });
    });

    modalElement.addEventListener('show.bs.modal', (event) => {
        if (!event.relatedTarget || !event.relatedTarget.classList.contains('edit-product-code')) {
            document.getElementById('modal-id').value = '0';
            document.getElementById('modal-code').value = '<?= erp_h($formData['id'] > 0 ? $formData['code'] : '') ?>';
            document.getElementById('modal-name').value = '<?= erp_h($formData['id'] > 0 ? $formData['name'] : '') ?>';
            document.getElementById('modal-unit').value = '<?= erp_h($formData['id'] > 0 ? $formData['unit'] : '') ?>';
            document.getElementById('modal-description').value = '<?= erp_h($formData['id'] > 0 ? $formData['description'] : '') ?>';
            document.getElementById('modal-active').checked = <?= (int) $formData['is_active'] === 1 ? 'true' : 'false' ?>;
        }
    });

    <?php if ($openModal): ?>
    modal.show();
    <?php endif; ?>
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
