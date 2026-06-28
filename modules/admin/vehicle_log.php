<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';

requireRole(['director', 'manager']);

if (($_GET['action'] ?? '') === 'vehicle_info') {
    header('Content-Type: application/json; charset=utf-8');
    $vehicleId = (int) ($_GET['id'] ?? 0);
    $vehicle = fetchOneSafe($pdo, "SELECT id, km_current FROM assets WHERE id = :id AND group_type = 'vehicle' LIMIT 1", ['id' => $vehicleId], ['id' => 0, 'km_current' => 0]);
    echo json_encode(['km_current' => (int) ($vehicle['km_current'] ?? 0)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensurePostCsrf();
    $assetId = (int) ($_POST['asset_id'] ?? 0);
    $useDate = trim((string) ($_POST['use_date'] ?? ''));
    $kmStart = trim((string) ($_POST['km_start'] ?? ''));
    $kmEnd = trim((string) ($_POST['km_end'] ?? ''));
    $destination = trim((string) ($_POST['destination'] ?? ''));
    $driverId = (int) ($_POST['driver_id'] ?? 0);
    $note = trim((string) ($_POST['note'] ?? ''));

    $vehicle = fetchOneSafe($pdo, "SELECT id, km_current FROM assets WHERE id = :id AND group_type = 'vehicle' LIMIT 1", ['id' => $assetId]);
    if ($vehicle === null) { $errors[] = 'Xe được chọn không hợp lệ.'; }
    if ($useDate === '') { $errors[] = 'Ngày sử dụng không được để trống.'; }
    if (!ctype_digit($kmStart)) { $errors[] = 'KM bắt đầu không hợp lệ.'; }
    if (!ctype_digit($kmEnd)) { $errors[] = 'KM kết thúc không hợp lệ.'; }
    if ($destination === '') { $errors[] = 'Điểm đến không được để trống.'; }
    if ($driverId <= 0) { $errors[] = 'Vui lòng chọn tài xế.'; }
    if (ctype_digit($kmStart) && ctype_digit($kmEnd) && (int) $kmEnd <= (int) $kmStart) { $errors[] = 'KM kết thúc phải lớn hơn KM bắt đầu.'; }
    if ($vehicle !== null && ctype_digit($kmStart) && (int) $kmStart < (int) ($vehicle['km_current'] ?? 0)) { $errors[] = 'KM bắt đầu không được nhỏ hơn công tơ mét hiện tại.'; }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO vehicle_logs (asset_id, use_date, km_start, km_end, destination, driver_id, note, created_by) VALUES (:asset_id, :use_date, :km_start, :km_end, :destination, :driver_id, :note, :created_by)');
            $insert->execute(['asset_id' => $assetId, 'use_date' => $useDate, 'km_start' => (int) $kmStart, 'km_end' => (int) $kmEnd, 'destination' => $destination, 'driver_id' => $driverId, 'note' => $note !== '' ? $note : null, 'created_by' => currentUserId()]);
            $update = $pdo->prepare('UPDATE assets SET km_current = :km_current WHERE id = :id');
            $update->execute(['km_current' => (int) $kmEnd, 'id' => $assetId]);
            $pdo->commit();
            setFlash('success', 'Đã lưu nhật ký xe.');
            redirect('modules/admin/vehicle_log.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = 'Không thể lưu nhật ký xe. Vui lòng thử lại.';
        }
    }
}

$vehicles = fetchAllSafe($pdo, "SELECT id, name, asset_code, license_plate, km_current FROM assets WHERE group_type = 'vehicle' ORDER BY name ASC");
$users = fetchAllSafe($pdo, 'SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name ASC');
$logs = fetchAllSafe($pdo, 'SELECT vl.use_date, vl.km_start, vl.km_end, (vl.km_end - vl.km_start) AS distance, vl.destination, vl.note, a.name AS vehicle_name, a.license_plate, u.full_name AS driver_name FROM vehicle_logs vl INNER JOIN assets a ON a.id = vl.asset_id LEFT JOIN users u ON u.id = vl.driver_id ORDER BY vl.use_date DESC, vl.id DESC LIMIT 50');

$pageTitle = 'Nhật ký xe';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => 'dashboard.php'],
    ['label' => 'Hành chính', 'url' => 'modules/admin/index.php'],
    ['label' => 'Nhật ký xe'],
];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<div class="row g-4"><div class="col-xl-4"><div class="card content-card"><div class="card-body p-4"><h1 class="h4 mb-3">Thêm nhật ký xe</h1><?php if ($errors !== []): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?><form method="post" class="row g-3" id="vehicleLogForm"><?= csrf_input() ?><div class="col-12"><label class="form-label">Xe</label><select name="asset_id" id="vehicleSelect" class="form-select" required><option value="">Chọn xe</option><?php foreach ($vehicles as $vehicle): ?><option value="<?= e((string) $vehicle['id']) ?>" data-km="<?= e((string) ($vehicle['km_current'] ?? 0)) ?>" <?= (string) old('asset_id') === (string) $vehicle['id'] ? 'selected' : '' ?>><?= e(($vehicle['asset_code'] ?? '') . ' - ' . ($vehicle['name'] ?? '') . ' (' . ($vehicle['license_plate'] ?? 'Chưa có biển số') . ')') ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Ngày sử dụng</label><input type="date" name="use_date" class="form-control" value="<?= e((string) old('use_date', date('Y-m-d'))) ?>" required></div><div class="col-md-6"><label class="form-label">Tài xế</label><select name="driver_id" class="form-select" required><option value="">Chọn tài xế</option><?php foreach ($users as $user): ?><option value="<?= e((string) $user['id']) ?>" <?= (string) old('driver_id') === (string) $user['id'] ? 'selected' : '' ?>><?= e($user['full_name']) ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">KM bắt đầu</label><input type="number" name="km_start" id="kmStart" class="form-control" min="0" step="1" value="<?= e((string) old('km_start')) ?>" required></div><div class="col-md-6"><label class="form-label">KM kết thúc</label><input type="number" name="km_end" class="form-control" min="0" step="1" value="<?= e((string) old('km_end')) ?>" required></div><div class="col-12"><label class="form-label">Điểm đến</label><input type="text" name="destination" class="form-control" value="<?= e((string) old('destination')) ?>" required></div><div class="col-12"><label class="form-label">Ghi chú</label><textarea name="note" class="form-control" rows="3"><?= e((string) old('note')) ?></textarea></div><div class="col-12 d-flex justify-content-end"><button type="submit" class="btn btn-primary">Lưu nhật ký xe</button></div></form></div></div></div><div class="col-xl-8"><div class="card content-card"><div class="card-header bg-white border-0 pt-4 px-4"><h2 class="h5 mb-0">Lịch sử sử dụng xe</h2></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Xe</th><th>Ngày sử dụng</th><th>KM bắt đầu</th><th>KM kết thúc</th><th>Quãng đường</th><th>Điểm đến</th><th>Tài xế</th><th>Ghi chú</th></tr></thead><tbody><?php if ($logs === []): ?><tr><td colspan="8" class="text-center py-4 text-muted">Chưa có nhật ký xe.</td></tr><?php else: ?><?php foreach ($logs as $log): ?><tr><td><div class="fw-semibold"><?= e($log['vehicle_name'] ?? '—') ?></div><small class="text-muted"><?= e($log['license_plate'] ?? '—') ?></small></td><td><?= e(formatDate($log['use_date'] ?? null)) ?></td><td><?= e(number_format((float) ($log['km_start'] ?? 0), 0, ',', '.')) ?></td><td><?= e(number_format((float) ($log['km_end'] ?? 0), 0, ',', '.')) ?></td><td><?= e(number_format((float) ($log['distance'] ?? 0), 0, ',', '.')) ?> km</td><td><?= e($log['destination'] ?? '—') ?></td><td><?= e($log['driver_name'] ?? '—') ?></td><td><?= e($log['note'] ?? '—') ?></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div></div></div>
<script>
(() => {
    const vehicleSelect = document.getElementById('vehicleSelect');
    const kmStart = document.getElementById('kmStart');
    const loadVehicleInfo = () => {
        if (!vehicleSelect || !kmStart || !vehicleSelect.value) return;
        fetch(`<?= e(basePath('modules/admin/vehicle_log.php')) ?>?action=vehicle_info&id=${vehicleSelect.value}`)
            .then(response => response.json())
            .then(data => {
                if (document.activeElement !== kmStart || !kmStart.value) {
                    kmStart.value = data.km_current ?? 0;
                }
            })
            .catch(() => {
                const option = vehicleSelect.options[vehicleSelect.selectedIndex];
                kmStart.value = option?.dataset.km || kmStart.value;
            });
    };
    vehicleSelect && vehicleSelect.addEventListener('change', loadVehicleInfo);
    loadVehicleInfo();
})();
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
