<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once __DIR__ . '/helpers.php';

requireRole(['director', 'manager']);

$vehicleFilter = trim((string) ($_GET['asset_id'] ?? ''));
$typeFilter = trim((string) ($_GET['type'] ?? ''));
$monthFilter = trim((string) ($_GET['month'] ?? date('Y-m')));
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensurePostCsrf();
    $assetId = (int) ($_POST['asset_id'] ?? 0);
    $expenseDate = trim((string) ($_POST['expense_date'] ?? ''));
    $type = trim((string) ($_POST['type'] ?? ''));
    $amount = trim((string) ($_POST['amount'] ?? '0'));
    $kmCurrent = trim((string) ($_POST['km_current'] ?? ''));
    $vendor = trim((string) ($_POST['vendor'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));

    $vehicle = fetchOneSafe($pdo, "SELECT id FROM assets WHERE id = :id AND group_type = 'vehicle' LIMIT 1", ['id' => $assetId]);
    if ($vehicle === null) { $errors[] = 'Xe được chọn không hợp lệ.'; }
    if ($expenseDate === '') { $errors[] = 'Ngày chi phí không được để trống.'; }
    if (!array_key_exists($type, adminVehicleExpenseTypes())) { $errors[] = 'Loại chi phí không hợp lệ.'; }
    if (!is_numeric($amount) || (float) $amount <= 0) { $errors[] = 'Số tiền phải lớn hơn 0.'; }
    if ($kmCurrent !== '' && !ctype_digit($kmCurrent)) { $errors[] = 'KM hiện tại không hợp lệ.'; }

    if ($errors === []) {
        $insert = $pdo->prepare('INSERT INTO vehicle_expenses (asset_id, expense_date, type, amount, km_current, vendor, note, created_by, created_at) VALUES (:asset_id, :expense_date, :type, :amount, :km_current, :vendor, :note, :created_by, NOW())');
        $insert->execute(['asset_id' => $assetId, 'expense_date' => $expenseDate, 'type' => $type, 'amount' => (float) $amount, 'km_current' => $kmCurrent !== '' ? (int) $kmCurrent : null, 'vendor' => $vendor !== '' ? $vendor : null, 'note' => $note !== '' ? $note : null, 'created_by' => currentUserId()]);
        setFlash('success', 'Đã ghi nhận chi phí xe.');
        redirect('modules/admin/vehicle_expense.php');
    }
}

$vehicles = fetchAllSafe($pdo, "SELECT id, name, asset_code, license_plate, km_current FROM assets WHERE group_type = 'vehicle' ORDER BY name ASC");
$summaryCards = fetchAllSafe($pdo, "SELECT a.name, a.license_plate, COALESCE(SUM(ve.amount), 0) AS total_amount FROM assets a LEFT JOIN vehicle_expenses ve ON ve.asset_id = a.id AND YEAR(ve.expense_date) = YEAR(CURDATE()) AND MONTH(ve.expense_date) = MONTH(CURDATE()) WHERE a.group_type = 'vehicle' GROUP BY a.id, a.name, a.license_plate ORDER BY total_amount DESC, a.name ASC");
$where = [];
$params = [];
if ($vehicleFilter !== '' && ctype_digit($vehicleFilter)) { $where[] = 've.asset_id = :asset_id'; $params['asset_id'] = (int) $vehicleFilter; }
if ($typeFilter !== '' && array_key_exists($typeFilter, adminVehicleExpenseTypes())) { $where[] = 've.type = :type'; $params['type'] = $typeFilter; }
if ($monthFilter !== '') { $where[] = "DATE_FORMAT(ve.expense_date, '%Y-%m') = :month_filter"; $params['month_filter'] = $monthFilter; }
$whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
$expenses = fetchAllSafe($pdo, "SELECT ve.expense_date, ve.type, ve.amount, ve.km_current, ve.vendor, ve.note, a.name AS vehicle_name, a.license_plate FROM vehicle_expenses ve INNER JOIN assets a ON a.id = ve.asset_id {$whereSql} ORDER BY ve.expense_date DESC, ve.id DESC LIMIT 100", $params);

$pageTitle = 'Chi phí xe';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => 'dashboard.php'],
    ['label' => 'Hành chính', 'url' => 'modules/admin/index.php'],
    ['label' => 'Chi phí xe'],
];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<div class="d-flex flex-wrap gap-3 mb-4"><?php foreach ($summaryCards as $summary): ?><div class="card stat-card" style="min-width:220px;"><div class="card-body"><div class="small text-muted"><?= e($summary['license_plate'] ?? 'Chưa có biển số') ?></div><div class="fw-semibold mb-2"><?= e($summary['name'] ?? 'Xe') ?></div><div class="h5 mb-0 text-success"><?= e(formatCurrency($summary['total_amount'] ?? 0)) ?></div></div></div><?php endforeach; ?></div>
<div class="row g-4"><div class="col-xl-4"><div class="card content-card mb-4"><div class="card-body p-4"><h1 class="h4 mb-3">Thêm chi phí xe</h1><?php if ($errors !== []): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?><form method="post" class="row g-3"><?= csrf_input() ?><div class="col-12"><label class="form-label">Xe</label><select name="asset_id" class="form-select" required><option value="">Chọn xe</option><?php foreach ($vehicles as $vehicle): ?><option value="<?= e((string) $vehicle['id']) ?>" <?= (string) old('asset_id', $vehicleFilter) === (string) $vehicle['id'] ? 'selected' : '' ?>><?= e(($vehicle['asset_code'] ?? '') . ' - ' . ($vehicle['name'] ?? '') . ' (' . ($vehicle['license_plate'] ?? 'Chưa có biển số') . ')') ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Ngày chi phí</label><input type="date" name="expense_date" class="form-control" value="<?= e((string) old('expense_date', date('Y-m-d'))) ?>" required></div><div class="col-md-6"><label class="form-label">Loại chi phí</label><select name="type" class="form-select" required><option value="">Chọn loại</option><?php foreach (adminVehicleExpenseTypes() as $value => $label): ?><option value="<?= e($value) ?>" <?= (string) old('type') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Số tiền</label><input type="number" name="amount" class="form-control" min="0" step="1000" value="<?= e((string) old('amount')) ?>" required></div><div class="col-md-6"><label class="form-label">KM hiện tại</label><input type="number" name="km_current" class="form-control" min="0" step="1" value="<?= e((string) old('km_current')) ?>"></div><div class="col-12"><label class="form-label">Nhà cung cấp / Vendor</label><input type="text" name="vendor" class="form-control" value="<?= e((string) old('vendor')) ?>"></div><div class="col-12"><label class="form-label">Ghi chú</label><textarea name="note" class="form-control" rows="3"><?= e((string) old('note')) ?></textarea></div><div class="col-12 d-flex justify-content-end"><button type="submit" class="btn btn-primary">Lưu chi phí</button></div></form></div></div><div class="card content-card"><div class="card-body p-4"><h2 class="h5 mb-3">Bộ lọc</h2><form method="get" class="row g-3"><div class="col-12"><label class="form-label">Xe</label><select name="asset_id" class="form-select"><option value="">Tất cả</option><?php foreach ($vehicles as $vehicle): ?><option value="<?= e((string) $vehicle['id']) ?>" <?= $vehicleFilter === (string) $vehicle['id'] ? 'selected' : '' ?>><?= e($vehicle['name'] ?? 'Xe') ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Loại</label><select name="type" class="form-select"><option value="">Tất cả</option><?php foreach (adminVehicleExpenseTypes() as $value => $label): ?><option value="<?= e($value) ?>" <?= $typeFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Tháng</label><input type="month" name="month" class="form-control" value="<?= e($monthFilter) ?>"></div><div class="col-12 d-grid"><button type="submit" class="btn btn-outline-primary">Lọc dữ liệu</button></div></form></div></div></div><div class="col-xl-8"><div class="card content-card"><div class="card-header bg-white border-0 pt-4 px-4"><h2 class="h5 mb-0">Danh sách chi phí xe</h2></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Xe</th><th>Ngày</th><th>Loại</th><th>Số tiền</th><th>KM hiện tại</th><th>Vendor</th></tr></thead><tbody><?php if ($expenses === []): ?><tr><td colspan="6" class="text-center py-4 text-muted">Không có dữ liệu chi phí phù hợp.</td></tr><?php else: ?><?php foreach ($expenses as $expense): ?><tr><td><div class="fw-semibold"><?= e($expense['vehicle_name'] ?? '—') ?></div><small class="text-muted"><?= e($expense['license_plate'] ?? '—') ?></small></td><td><?= e(formatDate($expense['expense_date'] ?? null)) ?></td><td><span class="badge text-bg-light"><?= e(adminVehicleExpenseTypeLabel((string) ($expense['type'] ?? ''))) ?></span></td><td class="fw-semibold text-success"><?= e(formatCurrency($expense['amount'] ?? 0)) ?></td><td><?= e(number_format((float) ($expense['km_current'] ?? 0), 0, ',', '.')) ?></td><td><div><?= e($expense['vendor'] ?? '—') ?></div><small class="text-muted"><?= e($expense['note'] ?? '') ?></small></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div></div></div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
