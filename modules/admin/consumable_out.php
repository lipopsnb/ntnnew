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
    $outDate = trim((string) ($_POST['out_date'] ?? ''));
    $qty = trim((string) ($_POST['qty'] ?? ''));
    $purpose = trim((string) ($_POST['purpose'] ?? ''));
    $usedBy = (int) ($_POST['used_by'] ?? 0);
    $note = trim((string) ($_POST['note'] ?? ''));

    $asset = fetchOneSafe($pdo, "SELECT id, name, qty_current FROM assets WHERE id = :id AND group_type = 'consumable' LIMIT 1", ['id' => $assetId]);
    if ($asset === null) { $errors[] = 'Vật tư tiêu hao không hợp lệ.'; }
    if ($outDate === '') { $errors[] = 'Ngày xuất không được để trống.'; }
    if (!ctype_digit($qty) || (int) $qty <= 0) { $errors[] = 'Số lượng xuất phải là số nguyên dương.'; }
    if ($purpose === '') { $errors[] = 'Mục đích sử dụng không được để trống.'; }
    if ($usedBy <= 0) { $errors[] = 'Vui lòng chọn người sử dụng.'; }
    if ($asset !== null && ctype_digit($qty) && (int) $qty > (int) floor((float) ($asset['qty_current'] ?? 0))) { $errors[] = 'Số lượng xuất vượt quá tồn kho hiện tại.'; }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO consumable_out (asset_id, out_date, qty, purpose, used_by, note, created_by, created_at) VALUES (:asset_id, :out_date, :qty, :purpose, :used_by, :note, :created_by, NOW())');
            $insert->execute(['asset_id' => $assetId, 'out_date' => $outDate, 'qty' => (int) $qty, 'purpose' => $purpose, 'used_by' => $usedBy, 'note' => $note !== '' ? $note : null, 'created_by' => currentUserId()]);
            $update = $pdo->prepare('UPDATE assets SET qty_current = qty_current - :qty WHERE id = :id');
            $update->execute(['qty' => (int) $qty, 'id' => $assetId]);
            $pdo->commit();
            setFlash('success', 'Đã xuất vật tư thành công.');
            redirect('modules/admin/consumable_out.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = 'Không thể lưu phiếu xuất vật tư. Vui lòng thử lại.';
        }
    }
}

$consumableAssets = fetchAllSafe($pdo, "SELECT id, name, asset_code, unit, qty_current FROM assets WHERE group_type = 'consumable' ORDER BY name ASC");
$users = fetchAllSafe($pdo, 'SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name ASC');
$recentRecords = fetchAllSafe($pdo, 'SELECT co.out_date, co.qty, co.purpose, co.note, a.name AS asset_name, a.unit, u.full_name AS used_by_name FROM consumable_out co INNER JOIN assets a ON a.id = co.asset_id LEFT JOIN users u ON u.id = co.used_by ORDER BY co.out_date DESC, co.id DESC LIMIT 20');

$pageTitle = 'Xuất vật tư';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => 'dashboard.php'],
    ['label' => 'Hành chính', 'url' => 'modules/admin/index.php'],
    ['label' => 'Xuất vật tư'],
];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<div class="row g-4"><div class="col-xl-5"><div class="card content-card"><div class="card-body p-4"><h1 class="h4 mb-3">Xuất vật tư tiêu hao</h1><?php if ($errors !== []): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?><form method="post" class="row g-3"><?= csrf_input() ?><div class="col-12"><label class="form-label">Vật tư</label><select name="asset_id" id="asset_id" class="form-select" required><option value="">Chọn vật tư</option><?php foreach ($consumableAssets as $asset): ?><option value="<?= e((string) $asset['id']) ?>" data-qty="<?= e((string) $asset['qty_current']) ?>" data-unit="<?= e((string) ($asset['unit'] ?? '')) ?>" <?= (string) old('asset_id', (string) $preselectedAssetId) === (string) $asset['id'] ? 'selected' : '' ?>><?= e(($asset['asset_code'] ?? '') . ' - ' . ($asset['name'] ?? '')) ?></option><?php endforeach; ?></select><div class="form-text" id="stockInfo">Tồn hiện tại sẽ hiển thị tại đây.</div></div><div class="col-md-6"><label class="form-label">Ngày xuất</label><input type="date" name="out_date" class="form-control" value="<?= e((string) old('out_date', date('Y-m-d'))) ?>" required></div><div class="col-md-6"><label class="form-label">Số lượng</label><input type="number" name="qty" class="form-control" min="1" step="1" value="<?= e((string) old('qty')) ?>" required></div><div class="col-12"><label class="form-label">Mục đích sử dụng</label><input type="text" name="purpose" class="form-control" value="<?= e((string) old('purpose')) ?>" required></div><div class="col-12"><label class="form-label">Người sử dụng</label><select name="used_by" class="form-select" required><option value="">Chọn người sử dụng</option><?php foreach ($users as $user): ?><option value="<?= e((string) $user['id']) ?>" <?= (string) old('used_by') === (string) $user['id'] ? 'selected' : '' ?>><?= e($user['full_name']) ?></option><?php endforeach; ?></select></div><div class="col-12"><label class="form-label">Ghi chú</label><textarea name="note" class="form-control" rows="3"><?= e((string) old('note')) ?></textarea></div><div class="col-12 d-flex justify-content-end"><button type="submit" class="btn btn-danger">Lưu phiếu xuất</button></div></form></div></div></div><div class="col-xl-7"><div class="card content-card"><div class="card-header bg-white border-0 pt-4 px-4"><h2 class="h5 mb-0">Lịch sử xuất vật tư gần đây</h2></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Vật tư</th><th>Ngày xuất</th><th>Số lượng</th><th>Mục đích</th><th>Người sử dụng</th></tr></thead><tbody><?php if ($recentRecords === []): ?><tr><td colspan="5" class="text-center py-4 text-muted">Chưa có phiếu xuất vật tư.</td></tr><?php else: ?><?php foreach ($recentRecords as $record): ?><tr><td class="fw-semibold"><?= e($record['asset_name'] ?? '—') ?></td><td><?= e(formatDate($record['out_date'] ?? null)) ?></td><td><?= e(number_format((float) ($record['qty'] ?? 0), 0, ',', '.')) ?> <?= e($record['unit'] ?? '') ?></td><td><?= e($record['purpose'] ?? '—') ?></td><td><?= e($record['used_by_name'] ?? '—') ?></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div></div></div>
<script>
(() => {
    const select = document.getElementById('asset_id');
    const stockInfo = document.getElementById('stockInfo');
    const updateStock = () => {
        if (!select) return;
        const option = select.options[select.selectedIndex];
        if (!option || !option.dataset.qty) {
            stockInfo.textContent = 'Tồn hiện tại sẽ hiển thị tại đây.';
            return;
        }
        stockInfo.textContent = `Tồn hiện tại: ${Number(option.dataset.qty).toLocaleString('vi-VN')} ${option.dataset.unit || ''}`;
    };
    updateStock();
    select && select.addEventListener('change', updateStock);
})();
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
