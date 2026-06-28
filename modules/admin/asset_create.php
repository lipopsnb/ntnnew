<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once __DIR__ . '/helpers.php';

requireRole(['director', 'manager']);

$assetId = (int) ($_GET['id'] ?? 0);
$isEdit = $assetId > 0;
$isViewMode = isset($_GET['view']) && $_GET['view'] === '1';
$errors = [];

$asset = [
    'asset_code' => adminGenerateAssetCode($pdo, (string) ($_GET['group_type'] ?? 'fixed_asset')),
    'name' => '',
    'group_type' => (string) ($_GET['group_type'] ?? 'fixed_asset'),
    'category' => '',
    'unit' => '',
    'purchase_date' => '',
    'purchase_price' => '0',
    'supplier' => '',
    'location' => '',
    'status' => 'active',
    'note' => '',
    'qty_current' => '0',
    'qty_min' => '0',
    'license_plate' => '',
    'km_current' => '',
    'registration_exp' => '',
    'insurance_exp' => '',
    'next_maintenance' => '',
];

if ($isEdit) {
    $assetRecord = fetchOneSafe($pdo, 'SELECT * FROM assets WHERE id = :id LIMIT 1', ['id' => $assetId]);
    if ($assetRecord === null) {
        setFlash('danger', 'Không tìm thấy tài sản cần xử lý.');
        redirect('modules/admin/assets.php');
    }
    $asset = array_merge($asset, $assetRecord);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensurePostCsrf();

    $assetId = (int) ($_POST['id'] ?? $assetId);
    $isEdit = $assetId > 0;
    $asset['asset_code'] = trim((string) ($_POST['asset_code'] ?? ''));
    $asset['name'] = trim((string) ($_POST['name'] ?? ''));
    $asset['group_type'] = trim((string) ($_POST['group_type'] ?? 'fixed_asset'));
    $asset['category'] = trim((string) ($_POST['category'] ?? ''));
    $asset['unit'] = trim((string) ($_POST['unit'] ?? ''));
    $asset['purchase_date'] = trim((string) ($_POST['purchase_date'] ?? ''));
    $asset['purchase_price'] = trim((string) ($_POST['purchase_price'] ?? '0'));
    $asset['supplier'] = trim((string) ($_POST['supplier'] ?? ''));
    $asset['location'] = trim((string) ($_POST['location'] ?? ''));
    $asset['status'] = trim((string) ($_POST['status'] ?? 'active'));
    $asset['note'] = trim((string) ($_POST['note'] ?? ''));
    $asset['qty_current'] = trim((string) ($_POST['qty_current'] ?? '0'));
    $asset['qty_min'] = trim((string) ($_POST['qty_min'] ?? '0'));
    $asset['license_plate'] = trim((string) ($_POST['license_plate'] ?? ''));
    $asset['km_current'] = trim((string) ($_POST['km_current'] ?? ''));
    $asset['registration_exp'] = trim((string) ($_POST['registration_exp'] ?? ''));
    $asset['insurance_exp'] = trim((string) ($_POST['insurance_exp'] ?? ''));
    $asset['next_maintenance'] = trim((string) ($_POST['next_maintenance'] ?? ''));

    if ($asset['asset_code'] === '') { $errors[] = 'Mã tài sản không được để trống.'; }
    if ($asset['name'] === '') { $errors[] = 'Tên tài sản không được để trống.'; }
    if (!in_array($asset['group_type'], ['fixed_asset', 'consumable', 'vehicle'], true)) { $errors[] = 'Nhóm tài sản không hợp lệ.'; }
    if (!in_array($asset['status'], ['active', 'maintenance', 'broken', 'disposed'], true)) { $errors[] = 'Trạng thái không hợp lệ.'; }
    if (!is_numeric($asset['purchase_price']) || (float) $asset['purchase_price'] < 0) { $errors[] = 'Giá mua phải là số không âm.'; }

    if ($asset['group_type'] === 'consumable') {
        if ($asset['unit'] === '') { $errors[] = 'Đơn vị tính của vật tư không được để trống.'; }
        if (!is_numeric($asset['qty_current']) || (float) $asset['qty_current'] < 0) { $errors[] = 'Tồn hiện tại phải là số không âm.'; }
        if (!is_numeric($asset['qty_min']) || (float) $asset['qty_min'] < 0) { $errors[] = 'Tồn tối thiểu phải là số không âm.'; }
    }

    if ($asset['group_type'] === 'vehicle') {
        if ($asset['license_plate'] === '') { $errors[] = 'Biển số xe không được để trống.'; }
        if ($asset['km_current'] !== '' && (!ctype_digit($asset['km_current']) || (int) $asset['km_current'] < 0)) { $errors[] = 'Số km hiện tại không hợp lệ.'; }
    }

    $duplicateAssetCode = (int) fetchScalarSafe(
        $pdo,
        'SELECT COUNT(*) FROM assets WHERE asset_code = :asset_code AND id != :id',
        ['asset_code' => $asset['asset_code'], 'id' => $assetId],
        0
    );
    if ($duplicateAssetCode > 0) {
        $errors[] = 'Mã tài sản đã tồn tại.';
    }

    if ($asset['license_plate'] !== '') {
        $duplicatePlate = (int) fetchScalarSafe(
            $pdo,
            'SELECT COUNT(*) FROM assets WHERE license_plate = :license_plate AND id != :id',
            ['license_plate' => $asset['license_plate'], 'id' => $assetId],
            0
        );
        if ($duplicatePlate > 0) {
            $errors[] = 'Biển số xe đã tồn tại.';
        }
    }

    if ($errors === []) {
        $params = [
            'asset_code' => $asset['asset_code'],
            'name' => $asset['name'],
            'group_type' => $asset['group_type'],
            'category' => $asset['category'] !== '' ? $asset['category'] : null,
            'unit' => $asset['unit'] !== '' ? $asset['unit'] : null,
            'purchase_date' => $asset['purchase_date'] !== '' ? $asset['purchase_date'] : null,
            'purchase_price' => (float) $asset['purchase_price'],
            'supplier' => $asset['supplier'] !== '' ? $asset['supplier'] : null,
            'location' => $asset['location'] !== '' ? $asset['location'] : null,
            'status' => $asset['status'],
            'qty_current' => $asset['group_type'] === 'consumable' ? (float) $asset['qty_current'] : 0,
            'qty_min' => $asset['group_type'] === 'consumable' ? (float) $asset['qty_min'] : 0,
            'license_plate' => $asset['group_type'] === 'vehicle' && $asset['license_plate'] !== '' ? $asset['license_plate'] : null,
            'km_current' => $asset['group_type'] === 'vehicle' && $asset['km_current'] !== '' ? (int) $asset['km_current'] : null,
            'registration_exp' => $asset['group_type'] === 'vehicle' && $asset['registration_exp'] !== '' ? $asset['registration_exp'] : null,
            'insurance_exp' => $asset['group_type'] === 'vehicle' && $asset['insurance_exp'] !== '' ? $asset['insurance_exp'] : null,
            'next_maintenance' => ($asset['group_type'] === 'vehicle' || $asset['group_type'] === 'fixed_asset') && $asset['next_maintenance'] !== '' ? $asset['next_maintenance'] : null,
            'note' => $asset['note'] !== '' ? $asset['note'] : null,
        ];

        if ($isEdit) {
            $params['id'] = $assetId;
            $statement = $pdo->prepare('UPDATE assets SET asset_code = :asset_code, name = :name, group_type = :group_type, category = :category, unit = :unit, purchase_date = :purchase_date, purchase_price = :purchase_price, supplier = :supplier, location = :location, status = :status, qty_current = :qty_current, qty_min = :qty_min, license_plate = :license_plate, km_current = :km_current, registration_exp = :registration_exp, insurance_exp = :insurance_exp, next_maintenance = :next_maintenance, note = :note WHERE id = :id');
            $statement->execute($params);
            setFlash('success', 'Cập nhật tài sản thành công.');
        } else {
            $statement = $pdo->prepare('INSERT INTO assets (asset_code, name, group_type, category, unit, purchase_date, purchase_price, supplier, location, status, qty_current, qty_min, license_plate, km_current, registration_exp, insurance_exp, next_maintenance, note) VALUES (:asset_code, :name, :group_type, :category, :unit, :purchase_date, :purchase_price, :supplier, :location, :status, :qty_current, :qty_min, :license_plate, :km_current, :registration_exp, :insurance_exp, :next_maintenance, :note)');
            $statement->execute($params);
            setFlash('success', 'Tạo tài sản thành công.');
        }

        $tabMap = ['fixed_asset' => 'fixed', 'consumable' => 'consumable', 'vehicle' => 'vehicle'];
        redirect('modules/admin/assets.php?tab=' . ($tabMap[$asset['group_type']] ?? 'fixed'));
    }
}

$pageTitle = $isViewMode ? 'Chi tiết tài sản' : ($isEdit ? 'Cập nhật tài sản' : 'Thêm tài sản');
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => 'dashboard.php'],
    ['label' => 'Hành chính', 'url' => 'modules/admin/index.php'],
    ['label' => 'Quản lý tài sản', 'url' => 'modules/admin/assets.php'],
    ['label' => $pageTitle],
];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<div class="card content-card"><div class="card-body p-4"><div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><h1 class="h4 mb-1"><?= e($pageTitle) ?></h1><p class="text-muted mb-0">Khai báo tập trung thông tin tài sản, vật tư và phương tiện.</p></div><?php if ($isViewMode): ?><a href="<?= e(basePath('modules/admin/asset_create.php?id=' . (string) $assetId)) ?>" class="btn btn-primary">Chuyển sang chỉnh sửa</a><?php endif; ?></div><?php if (!$isEdit): ?><div class="alert alert-info border-0">Mã tài sản được gợi ý tự động, bạn có thể chỉnh sửa trước khi lưu.</div><?php endif; ?><?php if ($errors !== []): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?><form method="post" id="assetForm"><?= csrf_input() ?><input type="hidden" name="id" value="<?= e((string) $assetId) ?>"><div class="row g-3"><div class="col-md-4"><label class="form-label">Mã tài sản</label><input type="text" name="asset_code" id="asset_code" class="form-control" value="<?= e((string) $asset['asset_code']) ?>" <?= $isViewMode ? 'disabled' : '' ?> required></div><div class="col-md-4"><label class="form-label">Tên tài sản</label><input type="text" name="name" class="form-control" value="<?= e((string) $asset['name']) ?>" <?= $isViewMode ? 'disabled' : '' ?> required></div><div class="col-md-4"><label class="form-label">Nhóm tài sản</label><select name="group_type" id="group_type" class="form-select" <?= $isViewMode ? 'disabled' : '' ?>><option value="fixed_asset" <?= (string) $asset['group_type'] === 'fixed_asset' ? 'selected' : '' ?>>Tài sản cố định</option><option value="consumable" <?= (string) $asset['group_type'] === 'consumable' ? 'selected' : '' ?>>Vật tư tiêu hao</option><option value="vehicle" <?= (string) $asset['group_type'] === 'vehicle' ? 'selected' : '' ?>>Xe cộ</option></select></div><div class="col-md-4"><label class="form-label">Danh mục</label><input type="text" name="category" class="form-control" value="<?= e((string) $asset['category']) ?>" <?= $isViewMode ? 'disabled' : '' ?>></div><div class="col-md-4"><label class="form-label">Đơn vị tính</label><input type="text" name="unit" class="form-control" value="<?= e((string) $asset['unit']) ?>" <?= $isViewMode ? 'disabled' : '' ?>></div><div class="col-md-4"><label class="form-label">Ngày mua</label><input type="date" name="purchase_date" class="form-control" value="<?= e((string) $asset['purchase_date']) ?>" <?= $isViewMode ? 'disabled' : '' ?>></div><div class="col-md-4"><label class="form-label">Giá mua</label><input type="number" step="1000" min="0" name="purchase_price" class="form-control" value="<?= e((string) $asset['purchase_price']) ?>" <?= $isViewMode ? 'disabled' : '' ?>></div><div class="col-md-4"><label class="form-label">Nhà cung cấp</label><input type="text" name="supplier" class="form-control" value="<?= e((string) $asset['supplier']) ?>" <?= $isViewMode ? 'disabled' : '' ?>></div><div class="col-md-4"><label class="form-label">Vị trí</label><input type="text" name="location" class="form-control" value="<?= e((string) $asset['location']) ?>" <?= $isViewMode ? 'disabled' : '' ?>></div><div class="col-md-4"><label class="form-label">Trạng thái</label><select name="status" class="form-select" <?= $isViewMode ? 'disabled' : '' ?>><option value="active" <?= (string) $asset['status'] === 'active' ? 'selected' : '' ?>>Đang sử dụng</option><option value="maintenance" <?= (string) $asset['status'] === 'maintenance' ? 'selected' : '' ?>>Bảo trì</option><option value="broken" <?= (string) $asset['status'] === 'broken' ? 'selected' : '' ?>>Hỏng</option><option value="disposed" <?= (string) $asset['status'] === 'disposed' ? 'selected' : '' ?>>Thanh lý</option></select></div><div class="col-md-8"><label class="form-label">Ghi chú</label><textarea name="note" class="form-control" rows="2" <?= $isViewMode ? 'disabled' : '' ?>><?= e((string) $asset['note']) ?></textarea></div></div><div id="consumableFields" class="mt-4"><h2 class="h6 mb-3">Thông tin vật tư tiêu hao</h2><div class="row g-3"><div class="col-md-4"><label class="form-label">Tồn hiện tại</label><input type="number" step="1" min="0" name="qty_current" class="form-control" value="<?= e((string) $asset['qty_current']) ?>" <?= $isViewMode ? 'disabled' : '' ?>></div><div class="col-md-4"><label class="form-label">Tồn tối thiểu</label><input type="number" step="1" min="0" name="qty_min" class="form-control" value="<?= e((string) $asset['qty_min']) ?>" <?= $isViewMode ? 'disabled' : '' ?>></div></div></div><div id="vehicleFields" class="mt-4"><h2 class="h6 mb-3">Thông tin xe cộ</h2><div class="row g-3"><div class="col-md-4"><label class="form-label">Biển số</label><input type="text" name="license_plate" class="form-control" value="<?= e((string) $asset['license_plate']) ?>" <?= $isViewMode ? 'disabled' : '' ?>></div><div class="col-md-4"><label class="form-label">Số km hiện tại</label><input type="number" step="1" min="0" name="km_current" class="form-control" value="<?= e((string) $asset['km_current']) ?>" <?= $isViewMode ? 'disabled' : '' ?>></div><div class="col-md-4"><label class="form-label">Bảo trì tiếp theo</label><input type="date" name="next_maintenance" class="form-control" value="<?= e((string) $asset['next_maintenance']) ?>" <?= $isViewMode ? 'disabled' : '' ?>></div><div class="col-md-4"><label class="form-label">Hạn đăng kiểm</label><input type="date" name="registration_exp" class="form-control" value="<?= e((string) $asset['registration_exp']) ?>" <?= $isViewMode ? 'disabled' : '' ?>></div><div class="col-md-4"><label class="form-label">Hạn bảo hiểm</label><input type="date" name="insurance_exp" class="form-control" value="<?= e((string) $asset['insurance_exp']) ?>" <?= $isViewMode ? 'disabled' : '' ?>></div></div></div><?php if (!$isViewMode): ?><div class="d-flex justify-content-end gap-2 mt-4"><a href="<?= e(basePath('modules/admin/assets.php')) ?>" class="btn btn-outline-secondary">Hủy</a><button type="submit" class="btn btn-primary"><?= $isEdit ? 'Cập nhật tài sản' : 'Lưu tài sản' ?></button></div><?php endif; ?></form></div></div>
<script>
(() => {
    const groupType = document.getElementById('group_type');
    const consumableFields = document.getElementById('consumableFields');
    const vehicleFields = document.getElementById('vehicleFields');
    const assetCodeInput = document.getElementById('asset_code');
    const generatedCodes = {
        fixed_asset: '<?= e(adminGenerateAssetCode($pdo, 'fixed_asset')) ?>',
        consumable: '<?= e(adminGenerateAssetCode($pdo, 'consumable')) ?>',
        vehicle: '<?= e(adminGenerateAssetCode($pdo, 'vehicle')) ?>'
    };
    let lastGeneratedCode = generatedCodes[groupType ? groupType.value : 'fixed_asset'];
    const toggleFields = () => {
        if (!groupType) return;
        const value = groupType.value;
        consumableFields.style.display = value === 'consumable' ? 'block' : 'none';
        vehicleFields.style.display = value === 'vehicle' ? 'block' : 'none';
        if (assetCodeInput && !<?= $isEdit ? 'true' : 'false' ?> && (assetCodeInput.value === '' || assetCodeInput.value === lastGeneratedCode)) {
            assetCodeInput.value = generatedCodes[value] || assetCodeInput.value;
            lastGeneratedCode = assetCodeInput.value;
        }
    };
    toggleFields();
    groupType && groupType.addEventListener('change', toggleFields);
})();
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
