<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';

requireRole(['director', 'manager', 'warehouse']);

$preselectedAssetId = (int) ($_GET['asset_id'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensurePostCsrf();

    $assetId = (int) ($_POST['asset_id'] ?? 0);
    $inDate = trim((string) ($_POST['in_date'] ?? ''));
    $qty = trim((string) ($_POST['qty'] ?? ''));
    $unitPrice = trim((string) ($_POST['unit_price'] ?? '0'));
    $supplier = trim((string) ($_POST['supplier'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));

    $asset = fetchOneSafe($pdo, "SELECT id, name FROM assets WHERE id = :id AND group_type = 'consumable' LIMIT 1", ['id' => $assetId]);
    if ($asset === null) { $errors[] = 'Vật tư tiêu hao không hợp lệ.'; }
    if ($inDate === '') { $errors[] = 'Ngày nhập không được để trống.'; }
    if (!ctype_digit($qty) || (int) $qty <= 0) { $errors[] = 'Số lượng nhập phải là số nguyên dương.'; }
    if (!is_numeric($unitPrice) || (float) $unitPrice < 0) { $errors[] = 'Đơn giá không hợp lệ.'; }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO consumable_in (asset_id, in_date, qty, unit_price, supplier, note, created_by, created_at) VALUES (:asset_id, :in_date, :qty, :unit_price, :supplier, :note, :created_by, NOW())');
            $insert->execute(['asset_id' => $assetId, 'in_date' => $inDate, 'qty' => (int) $qty, 'unit_price' => (float) $unitPrice, 'supplier' => $supplier !== '' ? $supplier : null, 'note' => $note !== '' ? $note : null, 'created_by' => currentUserId()]);
            $update = $pdo->prepare('UPDATE assets SET qty_current = qty_current + :qty WHERE id = :id');
            $update->execute(['qty' => (int) $qty, 'id' => $assetId]);
            $pdo->commit();
            setFlash('success', 'Đã nhập vật tư thành công.');
            redirect('modules/admin/consumable_in.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = 'Không thể lưu phiếu nhập vật tư. Vui lòng thử lại.';
        }
    }
}

$consumableAssets = fetchAllSafe($pdo, "SELECT id, name, asset_code, unit, qty_current FROM assets WHERE group_type = 'consumable' ORDER BY name ASC");
$recentRecords = fetchAllSafe($pdo, 'SELECT ci.in_date, ci.qty, ci.unit_price, ci.supplier, ci.note, a.name AS asset_name, a.unit FROM consumable_in ci INNER JOIN assets a ON a.id = ci.asset_id ORDER BY ci.in_date DESC, ci.id DESC LIMIT 20');

$pageTitle = 'Nhập vật tư';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => 'dashboard.php'],
    ['label' => 'Hành chính', 'url' => 'modules/admin/index.php'],
    ['label' => 'Nhập vật tư'],
];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<div class="row g-4"><div class="col-xl-5"><div class="card content-card"><div class="card-body p-4"><h1 class="h4 mb-3">Nhập vật tư tiêu hao</h1><?php if ($errors !== []): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?><form method="post" class="row g-3"><?= csrf_input() ?><div class="col-12"><label class="form-label">Vật tư</label><select name="asset_id" class="form-select" required><option value="">Chọn vật tư</option><?php foreach ($consumableAssets as $asset): ?><option value="<?= e((string) $asset['id']) ?>" <?= (string) old('asset_id', (string) $preselectedAssetId) === (string) $asset['id'] ? 'selected' : '' ?>><?= e(($asset['asset_code'] ?? '') . ' - ' . ($asset['name'] ?? '')) ?> (Tồn: <?= e(number_format((float) ($asset['qty_current'] ?? 0), 0, ',', '.')) ?> <?= e($asset['unit'] ?? '') ?>)</option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Ngày nhập</label><input type="date" name="in_date" class="form-control" value="<?= e((string) old('in_date', date('Y-m-d'))) ?>" required></div><div class="col-md-6"><label class="form-label">Số lượng</label><input type="number" name="qty" class="form-control" min="1" step="1" value="<?= e((string) old('qty')) ?>" required></div><div class="col-md-6"><label class="form-label">Đơn giá</label><input type="number" name="unit_price" class="form-control" min="0" step="1000" value="<?= e((string) old('unit_price')) ?>"></div><div class="col-md-6"><label class="form-label">Nhà cung cấp</label><input type="text" name="supplier" class="form-control" value="<?= e((string) old('supplier')) ?>"></div><div class="col-12"><label class="form-label">Ghi chú</label><textarea name="note" class="form-control" rows="3"><?= e((string) old('note')) ?></textarea></div><div class="col-12 d-flex justify-content-end"><button type="submit" class="btn btn-success">Lưu phiếu nhập</button></div></form></div></div></div><div class="col-xl-7"><div class="card content-card"><div class="card-header bg-white border-0 pt-4 px-4"><h2 class="h5 mb-0">Lịch sử nhập vật tư gần đây</h2></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Vật tư</th><th>Ngày nhập</th><th>Số lượng</th><th>Đơn giá</th><th>Tổng tiền</th><th>Nhà cung cấp</th><th>Ghi chú</th></tr></thead><tbody><?php if ($recentRecords === []): ?><tr><td colspan="7" class="text-center py-4 text-muted">Chưa có phiếu nhập vật tư.</td></tr><?php else: ?><?php foreach ($recentRecords as $record): ?><tr><td class="fw-semibold"><?= e($record['asset_name'] ?? '—') ?></td><td><?= e(formatDate($record['in_date'] ?? null)) ?></td><td><?= e(number_format((float) ($record['qty'] ?? 0), 0, ',', '.')) ?> <?= e($record['unit'] ?? '') ?></td><td><?= e(formatCurrency($record['unit_price'] ?? 0)) ?></td><td><?= e(formatCurrency(((float) ($record['qty'] ?? 0)) * ((float) ($record['unit_price'] ?? 0)))) ?></td><td><?= e($record['supplier'] ?? '—') ?></td><td><?= e($record['note'] ?? '—') ?></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div></div></div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
