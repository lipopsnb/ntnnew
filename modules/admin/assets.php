<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once __DIR__ . '/helpers.php';

requireRole(['director', 'manager']);

$tab = (string) ($_GET['tab'] ?? 'fixed');
if (!in_array($tab, ['fixed', 'consumable', 'vehicle'], true)) {
    $tab = 'fixed';
}

$fixedAssets = fetchAllSafe(
    $pdo,
    "SELECT id, asset_code, name, category, purchase_date, purchase_price, location, status, next_maintenance
     FROM assets
     WHERE group_type = 'fixed_asset'
     ORDER BY purchase_date DESC, id DESC"
);
$maintenanceDueAssets = fetchAllSafe(
    $pdo,
    "SELECT id, asset_code, name, next_maintenance
     FROM assets
     WHERE group_type = 'fixed_asset' AND next_maintenance IS NOT NULL AND next_maintenance <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
     ORDER BY next_maintenance ASC"
);
$consumables = fetchAllSafe(
    $pdo,
    "SELECT id, asset_code, name, unit, qty_current, qty_min, status
     FROM assets
     WHERE group_type = 'consumable'
     ORDER BY name ASC"
);
$vehicles = fetchAllSafe(
    $pdo,
    "SELECT id, asset_code, name, license_plate, km_current, registration_exp, insurance_exp, status
     FROM assets
     WHERE group_type = 'vehicle'
     ORDER BY name ASC"
);

$pageTitle = 'Quản lý tài sản';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => 'dashboard.php'],
    ['label' => 'Hành chính', 'url' => 'modules/admin/index.php'],
    ['label' => 'Quản lý tài sản'],
];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Quản lý tài sản</h1>
        <p class="text-muted mb-0">Theo dõi tài sản cố định, vật tư tiêu hao và xe cộ nội bộ.</p>
    </div>
    <a href="<?= e(basePath('modules/admin/asset_create.php')) ?>" class="btn btn-primary"><i class="fa-solid fa-plus me-2"></i>Thêm tài sản</a>
</div>

<ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link <?= $tab === 'fixed' ? 'active' : '' ?>" href="<?= e(basePath('modules/admin/assets.php?tab=fixed')) ?>">Tài sản cố định</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'consumable' ? 'active' : '' ?>" href="<?= e(basePath('modules/admin/assets.php?tab=consumable')) ?>">Vật tư tiêu hao</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'vehicle' ? 'active' : '' ?>" href="<?= e(basePath('modules/admin/assets.php?tab=vehicle')) ?>">Xe cộ</a></li>
</ul>

<?php if ($tab === 'fixed'): ?>
    <?php if ($maintenanceDueAssets !== []): ?>
        <div class="alert alert-warning border-0 shadow-sm">
            <div class="fw-semibold mb-2"><i class="fa-solid fa-screwdriver-wrench me-2"></i>Tài sản cần bảo trì trong 7 ngày</div>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($maintenanceDueAssets as $asset): ?>
                    <span class="badge rounded-pill text-bg-warning text-dark"><?= e(($asset['asset_code'] ?? '') . ' - ' . ($asset['name'] ?? '')) ?> · <?= e(formatDate($asset['next_maintenance'] ?? null)) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    <div class="card content-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Mã tài sản</th><th>Tên tài sản</th><th>Danh mục</th><th>Ngày mua</th><th>Giá mua</th><th>Vị trí</th><th>Trạng thái</th><th class="text-end">Actions</th></tr></thead><tbody><?php if ($fixedAssets === []): ?><tr><td colspan="8" class="text-center py-4 text-muted">Chưa có tài sản cố định.</td></tr><?php else: ?><?php foreach ($fixedAssets as $asset): ?><tr><td class="fw-semibold"><?= e($asset['asset_code'] ?? '—') ?></td><td><?= e($asset['name'] ?? '—') ?></td><td><?= e($asset['category'] ?? '—') ?></td><td><?= e(formatDate($asset['purchase_date'] ?? null)) ?></td><td><?= e(formatCurrency($asset['purchase_price'] ?? 0)) ?></td><td><?= e($asset['location'] ?? '—') ?></td><td><span class="badge text-bg-<?= e(adminAssetStatusBadgeClass((string) ($asset['status'] ?? ''))) ?>"><?= e(adminAssetStatusLabel((string) ($asset['status'] ?? ''))) ?></span></td><td class="text-end"><div class="btn-group"><a href="<?= e(basePath('modules/admin/asset_create.php?id=' . (string) $asset['id'] . '&view=1')) ?>" class="btn btn-sm btn-outline-info">Xem</a><a href="<?= e(basePath('modules/admin/asset_create.php?id=' . (string) $asset['id'])) ?>" class="btn btn-sm btn-outline-primary">Sửa</a><a href="<?= e(basePath('modules/admin/maintenance.php?asset_id=' . (string) $asset['id'])) ?>" class="btn btn-sm btn-outline-warning">Bảo trì</a></div></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div></div>
<?php elseif ($tab === 'consumable'): ?>
    <div class="card content-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Mã vật tư</th><th>Tên vật tư</th><th>Đơn vị</th><th>Tồn hiện tại</th><th>Tồn tối thiểu</th><th>Trạng thái</th><th class="text-end">Thao tác</th></tr></thead><tbody><?php if ($consumables === []): ?><tr><td colspan="7" class="text-center py-4 text-muted">Chưa có vật tư tiêu hao.</td></tr><?php else: ?><?php foreach ($consumables as $asset): ?><?php $isLow = (float) ($asset['qty_current'] ?? 0) < (float) ($asset['qty_min'] ?? 0); ?><tr><td class="fw-semibold"><?= e($asset['asset_code'] ?? '—') ?></td><td><?= e($asset['name'] ?? '—') ?></td><td><?= e($asset['unit'] ?? '—') ?></td><td class="<?= $isLow ? 'text-danger fw-semibold' : '' ?>"><?= e(number_format((float) ($asset['qty_current'] ?? 0), 0, ',', '.')) ?></td><td><?= e(number_format((float) ($asset['qty_min'] ?? 0), 0, ',', '.')) ?></td><td><span class="badge text-bg-<?= e(adminAssetStatusBadgeClass((string) ($asset['status'] ?? ''))) ?>"><?= e(adminAssetStatusLabel((string) ($asset['status'] ?? ''))) ?></span><?php if ($isLow): ?><span class="badge bg-transparent text-danger border border-danger ms-1">Dưới định mức</span><?php endif; ?></td><td class="text-end"><div class="btn-group"><a href="<?= e(basePath('modules/admin/consumable_in.php?asset_id=' . (string) $asset['id'])) ?>" class="btn btn-sm btn-outline-success">Nhập vật tư</a><a href="<?= e(basePath('modules/admin/consumable_out.php?asset_id=' . (string) $asset['id'])) ?>" class="btn btn-sm btn-outline-danger">Xuất vật tư</a></div></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div></div>
<?php else: ?>
    <div class="card content-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Mã xe</th><th>Tên xe</th><th>Biển số</th><th>KM hiện tại</th><th>Đăng kiểm</th><th>Bảo hiểm</th><th>Trạng thái</th></tr></thead><tbody><?php if ($vehicles === []): ?><tr><td colspan="7" class="text-center py-4 text-muted">Chưa có dữ liệu xe cộ.</td></tr><?php else: ?><?php foreach ($vehicles as $vehicle): ?><?php $registrationDue = !empty($vehicle['registration_exp']) && strtotime((string) $vehicle['registration_exp']) <= strtotime('+30 days'); $insuranceDue = !empty($vehicle['insurance_exp']) && strtotime((string) $vehicle['insurance_exp']) <= strtotime('+30 days'); ?><tr class="<?= ($registrationDue || $insuranceDue) ? 'table-warning' : '' ?>"><td class="fw-semibold"><?= e($vehicle['asset_code'] ?? '—') ?></td><td><?= e($vehicle['name'] ?? '—') ?></td><td><?= e($vehicle['license_plate'] ?? '—') ?></td><td><?= e(number_format((float) ($vehicle['km_current'] ?? 0), 0, ',', '.')) ?></td><td><?= e(formatDate($vehicle['registration_exp'] ?? null)) ?></td><td><?= e(formatDate($vehicle['insurance_exp'] ?? null)) ?></td><td><span class="badge text-bg-<?= e(adminAssetStatusBadgeClass((string) ($vehicle['status'] ?? ''))) ?>"><?= e(adminAssetStatusLabel((string) ($vehicle['status'] ?? ''))) ?></span></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div></div>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
