<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once __DIR__ . '/helpers.php';

requireRole(['director', 'manager', 'production']);

$assetFilter = trim((string) ($_GET['asset_id'] ?? ''));
$typeFilter = trim((string) ($_GET['type'] ?? ''));
$fromDate = trim((string) ($_GET['from_date'] ?? ''));
$toDate = trim((string) ($_GET['to_date'] ?? ''));
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensurePostCsrf();

    $assetId = (int) ($_POST['asset_id'] ?? 0);
    $maintenanceDate = trim((string) ($_POST['maintenance_date'] ?? ''));
    $type = trim((string) ($_POST['type'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $cost = trim((string) ($_POST['cost'] ?? '0'));
    $performedBy = trim((string) ($_POST['performed_by'] ?? ''));
    $nextDate = trim((string) ($_POST['next_date'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));

    $asset = fetchOneSafe($pdo, "SELECT id FROM assets WHERE id = :id AND group_type IN ('fixed_asset', 'vehicle') LIMIT 1", ['id' => $assetId]);
    if ($asset === null) { $errors[] = 'Tài sản bảo trì không hợp lệ.'; }
    if ($maintenanceDate === '') { $errors[] = 'Ngày bảo trì không được để trống.'; }
    if (!in_array($type, ['preventive', 'corrective'], true)) { $errors[] = 'Loại bảo trì không hợp lệ.'; }
    if ($description === '') { $errors[] = 'Mô tả bảo trì không được để trống.'; }
    if (!is_numeric($cost) || (float) $cost < 0) { $errors[] = 'Chi phí bảo trì không hợp lệ.'; }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO maintenance_logs (asset_id, maintenance_date, type, description, cost, performed_by, next_date, note, created_by, created_at) VALUES (:asset_id, :maintenance_date, :type, :description, :cost, :performed_by, :next_date, :note, :created_by, NOW())');
            $insert->execute(['asset_id' => $assetId, 'maintenance_date' => $maintenanceDate, 'type' => $type, 'description' => $description, 'cost' => (float) $cost, 'performed_by' => $performedBy !== '' ? $performedBy : null, 'next_date' => $nextDate !== '' ? $nextDate : null, 'note' => $note !== '' ? $note : null, 'created_by' => currentUserId()]);
            $update = $pdo->prepare('UPDATE assets SET next_maintenance = :next_maintenance WHERE id = :id');
            $update->execute(['next_maintenance' => $nextDate !== '' ? $nextDate : null, 'id' => $assetId]);
            $pdo->commit();
            setFlash('success', 'Đã ghi nhận lịch sử bảo trì.');
            redirect('modules/admin/maintenance.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = 'Không thể lưu lịch sử bảo trì. Vui lòng thử lại.';
        }
    }
}

$assets = fetchAllSafe($pdo, "SELECT id, asset_code, name, group_type FROM assets WHERE group_type IN ('fixed_asset', 'vehicle') ORDER BY name ASC");
$where = [];
$params = [];
if ($assetFilter !== '' && ctype_digit($assetFilter)) { $where[] = 'ml.asset_id = :asset_id'; $params['asset_id'] = (int) $assetFilter; }
if ($typeFilter !== '' && in_array($typeFilter, ['preventive', 'corrective'], true)) { $where[] = 'ml.type = :type'; $params['type'] = $typeFilter; }
if ($fromDate !== '') { $where[] = 'ml.maintenance_date >= :from_date'; $params['from_date'] = $fromDate; }
if ($toDate !== '') { $where[] = 'ml.maintenance_date <= :to_date'; $params['to_date'] = $toDate; }
$whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
$logs = fetchAllSafe($pdo, "SELECT ml.maintenance_date, ml.type, ml.description, ml.cost, ml.performed_by, ml.next_date, ml.note, a.name AS asset_name, a.asset_code FROM maintenance_logs ml INNER JOIN assets a ON a.id = ml.asset_id {$whereSql} ORDER BY ml.maintenance_date DESC, ml.id DESC LIMIT 100", $params);

$pageTitle = 'Bảo trì tài sản';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => 'dashboard.php'],
    ['label' => 'Hành chính', 'url' => 'modules/admin/index.php'],
    ['label' => 'Bảo trì'],
];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<div class="row g-4"><div class="col-xl-4"><div class="card content-card mb-4"><div class="card-body p-4"><h1 class="h4 mb-3">Thêm nhật ký bảo trì</h1><?php if ($errors !== []): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?><form method="post" class="row g-3"><?= csrf_input() ?><div class="col-12"><label class="form-label">Tài sản</label><select name="asset_id" class="form-select" required><option value="">Chọn tài sản</option><?php foreach ($assets as $asset): ?><option value="<?= e((string) $asset['id']) ?>" <?= (string) old('asset_id', $assetFilter) === (string) $asset['id'] ? 'selected' : '' ?>><?= e(($asset['asset_code'] ?? '') . ' - ' . ($asset['name'] ?? '') . ' (' . adminGroupLabel((string) ($asset['group_type'] ?? '')) . ')') ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Ngày bảo trì</label><input type="date" name="maintenance_date" class="form-control" value="<?= e((string) old('maintenance_date', date('Y-m-d'))) ?>" required></div><div class="col-md-6"><label class="form-label">Loại</label><select name="type" class="form-select" required><option value="preventive" <?= old('type') === 'preventive' ? 'selected' : '' ?>>Preventive</option><option value="corrective" <?= old('type') === 'corrective' ? 'selected' : '' ?>>Corrective</option></select></div><div class="col-12"><label class="form-label">Mô tả</label><textarea name="description" class="form-control" rows="3" required><?= e((string) old('description')) ?></textarea></div><div class="col-md-6"><label class="form-label">Chi phí</label><input type="number" name="cost" class="form-control" min="0" step="1000" value="<?= e((string) old('cost')) ?>"></div><div class="col-md-6"><label class="form-label">Đơn vị thực hiện</label><input type="text" name="performed_by" class="form-control" value="<?= e((string) old('performed_by')) ?>"></div><div class="col-md-6"><label class="form-label">Lịch kế tiếp</label><input type="date" name="next_date" class="form-control" value="<?= e((string) old('next_date')) ?>"></div><div class="col-12"><label class="form-label">Ghi chú</label><textarea name="note" class="form-control" rows="2"><?= e((string) old('note')) ?></textarea></div><div class="col-12 d-flex justify-content-end"><button type="submit" class="btn btn-primary">Lưu bảo trì</button></div></form></div></div><div class="card content-card"><div class="card-body p-4"><h2 class="h5 mb-3">Bộ lọc dữ liệu</h2><form method="get" class="row g-3"><div class="col-12"><label class="form-label">Tài sản</label><select name="asset_id" class="form-select"><option value="">Tất cả</option><?php foreach ($assets as $asset): ?><option value="<?= e((string) $asset['id']) ?>" <?= $assetFilter === (string) $asset['id'] ? 'selected' : '' ?>><?= e(($asset['asset_code'] ?? '') . ' - ' . ($asset['name'] ?? '')) ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Loại</label><select name="type" class="form-select"><option value="">Tất cả</option><option value="preventive" <?= $typeFilter === 'preventive' ? 'selected' : '' ?>>Preventive</option><option value="corrective" <?= $typeFilter === 'corrective' ? 'selected' : '' ?>>Corrective</option></select></div><div class="col-md-6"><label class="form-label">Từ ngày</label><input type="date" name="from_date" class="form-control" value="<?= e($fromDate) ?>"></div><div class="col-md-6"><label class="form-label">Đến ngày</label><input type="date" name="to_date" class="form-control" value="<?= e($toDate) ?>"></div><div class="col-md-6 d-grid align-self-end"><button type="submit" class="btn btn-outline-primary">Lọc dữ liệu</button></div></form></div></div></div><div class="col-xl-8"><div class="card content-card"><div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center"><h2 class="h5 mb-0">Danh sách bảo trì</h2><span class="badge text-bg-light"><?= count($logs) ?> bản ghi</span></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Tài sản</th><th>Ngày</th><th>Loại</th><th>Mô tả</th><th>Chi phí</th><th>Thực hiện</th><th>Lần kế tiếp</th></tr></thead><tbody><?php if ($logs === []): ?><tr><td colspan="7" class="text-center py-4 text-muted">Không có dữ liệu bảo trì phù hợp.</td></tr><?php else: ?><?php foreach ($logs as $log): ?><tr><td><div class="fw-semibold"><?= e($log['asset_name'] ?? '—') ?></div><small class="text-muted"><?= e($log['asset_code'] ?? '') ?></small></td><td><?= e(formatDate($log['maintenance_date'] ?? null)) ?></td><td><span class="badge text-bg-<?= e(adminMaintenanceTypeBadgeClass((string) ($log['type'] ?? ''))) ?>"><?= e(adminMaintenanceTypeLabel((string) ($log['type'] ?? ''))) ?></span></td><td><div><?= e($log['description'] ?? '—') ?></div><small class="text-muted"><?= e($log['note'] ?? '') ?></small></td><td><?= e(formatCurrency($log['cost'] ?? 0)) ?></td><td><?= e($log['performed_by'] ?? '—') ?></td><td><?= e(formatDate($log['next_date'] ?? null)) ?></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div></div></div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
