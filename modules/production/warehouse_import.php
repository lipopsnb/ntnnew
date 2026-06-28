<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireLogin();

requireRole('director', 'accountant', 'manager', 'production', 'warehouse');
$pdo = getDBConnection();

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('formatQty')) {
    function formatQty($value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }
}
if (!function_exists('statusBadgeClass')) {
    function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'partial' => 'warning',
            default => 'secondary',
        };
    }
}
if (!function_exists('statusLabel')) {
    function statusLabel(string $status): string
    {
        return match ($status) {
            'completed' => 'Hoàn thành',
            'partial' => 'Một phần',
            default => 'Chờ xử lý',
        };
    }
}
if (!function_exists('nextDocCode')) {
    function nextDocCode(PDO $pdo, string $prefix, ?string $docDate = null): string
    {
        if ($docDate !== null && date('Y-m-d', strtotime($docDate)) === date('Y-m-d') && function_exists('generateDocCode')) {
            return generateDocCode($pdo, $prefix);
        }
        $docDate = $docDate ?: date('Y-m-d');
        $dateKey = date('Y-m-d', strtotime($docDate));
        $stmt = $pdo->prepare('SELECT id, last_seq FROM document_sequences WHERE doc_type = ? AND doc_date = ? FOR UPDATE');
        $stmt->execute([$prefix, $dateKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $next = $row ? ((int) $row['last_seq'] + 1) : 1;
        if ($row) {
            $update = $pdo->prepare('UPDATE document_sequences SET last_seq = ? WHERE id = ?');
            $update->execute([$next, $row['id']]);
        } else {
            $insert = $pdo->prepare('INSERT INTO document_sequences (doc_type, doc_date, last_seq) VALUES (?, ?, ?)');
            $insert->execute([$prefix, $dateKey, $next]);
        }
        return sprintf('%s-%s-%03d', $prefix, date('Ymd', strtotime($dateKey)), $next);
    }
}
if (!function_exists('adjustWarehouseStock')) {
    function adjustWarehouseStock(PDO $pdo, int $productId, float $pending, float $completed = 0, float $defect = 0): void
    {
        $stmt = $pdo->prepare('SELECT id, qty_pending, qty_completed, qty_defect FROM warehouse_stock WHERE product_code_id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $update = $pdo->prepare('UPDATE warehouse_stock SET qty_pending = ?, qty_completed = ?, qty_defect = ?, updated_at = NOW() WHERE id = ?');
            $update->execute([
                max(0, (float) $row['qty_pending'] + $pending),
                max(0, (float) $row['qty_completed'] + $completed),
                max(0, (float) $row['qty_defect'] + $defect),
                $row['id'],
            ]);
        } else {
            $insert = $pdo->prepare('INSERT INTO warehouse_stock (product_code_id, qty_pending, qty_completed, qty_defect, updated_at) VALUES (?, ?, ?, ?, NOW())');
            $insert->execute([$productId, max(0, $pending), max(0, $completed), max(0, $defect)]);
        }
    }
}
if (!function_exists('addWarehouseStockLog')) {
    function addWarehouseStockLog(PDO $pdo, int $productId, string $date, string $txnType, string $stockType, float $qtyChange, string $refTable, int $refId, string $note, ?int $createdBy): void
    {
        $stmt = $pdo->prepare('INSERT INTO warehouse_stock_log (product_code_id, log_date, txn_type, stock_type, qty_change, ref_table, ref_id, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$productId, $date, $txnType, $stockType, $qtyChange, $refTable, $refId, $note, $createdBy]);
    }
}

$formData = [
    'id' => 0,
    'import_date' => date('Y-m-d'),
    'product_code_id' => 0,
    'description' => '',
    'quantity' => '',
    'note' => '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc đã hết hạn.');
        header('Location: /ntn_erp/modules/production/warehouse_import.php');
        exit;
    }

    $action = $_POST['action'] ?? '';
    $userId = (int) (currentUser()['id'] ?? 0);

    if ($action === 'save_import') {
        $formData = [
            'id' => (int) ($_POST['id'] ?? 0),
            'import_date' => trim((string) ($_POST['import_date'] ?? date('Y-m-d'))),
            'product_code_id' => (int) ($_POST['product_code_id'] ?? 0),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'quantity' => trim((string) ($_POST['quantity'] ?? '')),
            'note' => trim((string) ($_POST['note'] ?? '')),
        ];
        $quantity = (float) $formData['quantity'];
        if ($formData['product_code_id'] <= 0 || $formData['import_date'] === '' || $quantity <= 0) {
            $errors[] = 'Vui lòng nhập đầy đủ ngày nhập, sản phẩm và số lượng.';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                if ($formData['id'] > 0) {
                    $stmt = $pdo->prepare('SELECT * FROM warehouse_imports WHERE id = ? FOR UPDATE');
                    $stmt->execute([$formData['id']]);
                    $old = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$old || $old['status'] !== 'pending') {
                        throw new RuntimeException('Chỉ được sửa phiếu đang chờ xử lý.');
                    }
                    adjustWarehouseStock($pdo, (int) $old['product_code_id'], -(float) $old['quantity']);
                    adjustWarehouseStock($pdo, $formData['product_code_id'], $quantity);
                    addWarehouseStockLog($pdo, (int) $old['product_code_id'], $formData['import_date'], 'import_edit', 'pending', -(float) $old['quantity'], 'warehouse_imports', $formData['id'], 'Hoàn tác trước khi cập nhật phiếu nhập', $userId ?: null);
                    addWarehouseStockLog($pdo, $formData['product_code_id'], $formData['import_date'], 'import_edit', 'pending', $quantity, 'warehouse_imports', $formData['id'], 'Cập nhật phiếu nhập kho', $userId ?: null);
                    $update = $pdo->prepare('UPDATE warehouse_imports SET import_date = ?, product_code_id = ?, description = ?, quantity = ?, note = ?, updated_at = NOW() WHERE id = ?');
                    $update->execute([$formData['import_date'], $formData['product_code_id'], $formData['description'], $quantity, $formData['note'], $formData['id']]);
                    $pdo->commit();
                    setFlash('success', 'Đã cập nhật phiếu nhập kho.');
                } else {
                    $importNo = nextDocCode($pdo, 'WI', $formData['import_date']);
                    $insert = $pdo->prepare('INSERT INTO warehouse_imports (import_no, import_date, product_code_id, description, quantity, quantity_sent, note, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, NOW(), NOW())');
                    $insert->execute([$importNo, $formData['import_date'], $formData['product_code_id'], $formData['description'], $quantity, $formData['note'], 'pending', $userId ?: null]);
                    $importId = (int) $pdo->lastInsertId();
                    adjustWarehouseStock($pdo, $formData['product_code_id'], $quantity);
                    addWarehouseStockLog($pdo, $formData['product_code_id'], $formData['import_date'], 'import', 'pending', $quantity, 'warehouse_imports', $importId, 'Nhập kho từ khách hàng', $userId ?: null);
                    $pdo->commit();
                    setFlash('success', 'Đã tạo phiếu nhập kho ' . $importNo . '.');
                }
                header('Location: /ntn_erp/modules/production/warehouse_import.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = $e->getMessage();
            }
        }
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0 && $formData['id'] === 0) {
    $stmt = $pdo->prepare('SELECT * FROM warehouse_imports WHERE id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($record && $record['status'] === 'pending') {
        $formData = array_merge($formData, $record, ['id' => (int) $record['id']]);
    }
}

$filters = [
    'status' => trim((string) ($_GET['status'] ?? '')),
    'date_from' => trim((string) ($_GET['date_from'] ?? date('Y-m-01'))),
    'date_to' => trim((string) ($_GET['date_to'] ?? date('Y-m-t'))),
];
$where = ['1=1'];
$params = [];
if ($filters['status'] !== '') {
    $where[] = 'wi.status = ?';
    $params[] = $filters['status'];
}
if ($filters['date_from'] !== '') {
    $where[] = 'wi.import_date >= ?';
    $params[] = $filters['date_from'];
}
if ($filters['date_to'] !== '') {
    $where[] = 'wi.import_date <= ?';
    $params[] = $filters['date_to'];
}
$sql = 'SELECT wi.*, pc.product_code, pc.description AS product_name FROM warehouse_imports wi INNER JOIN product_codes pc ON pc.id = wi.product_code_id WHERE ' . implode(' AND ', $where) . ' ORDER BY wi.import_date DESC, wi.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$imports = $stmt->fetchAll(PDO::FETCH_ASSOC);
$productCodes = $pdo->query('SELECT id, product_code, description FROM product_codes WHERE is_active = 1 ORDER BY product_code ASC')->fetchAll(PDO::FETCH_ASSOC);
$viewId = (int) ($_GET['view'] ?? 0);
$viewImport = null;
if ($viewId > 0) {
    $stmt = $pdo->prepare('SELECT wi.*, pc.product_code, pc.description AS product_name FROM warehouse_imports wi INNER JOIN product_codes pc ON pc.id = wi.product_code_id WHERE wi.id = ? LIMIT 1');
    $stmt->execute([$viewId]);
    $viewImport = $stmt->fetch(PDO::FETCH_ASSOC);
}
$flash = getFlash();
$csrfToken = generateCSRF();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Nhập kho từ khách</h1>
            <p class="text-muted mb-0">Theo dõi phiếu nhập kho nguyên vật liệu từ khách hàng.</p>
        </div>
        <a href="/ntn_erp/modules/production/warehouse_import.php" class="btn btn-outline-secondary">Làm mới</a>
    </div>

    <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert"><?= e($flash['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <?php if ($viewImport): ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Chi tiết phiếu <?= e($viewImport['import_no']) ?></h2>
                <a href="/ntn_erp/modules/production/warehouse_import.php" class="btn btn-sm btn-outline-secondary">Đóng</a>
            </div>
            <div class="card-body row g-3">
                <div class="col-md-4"><div class="text-muted small">Ngày nhập</div><div class="fw-semibold"><?= e(date('d/m/Y', strtotime($viewImport['import_date']))) ?></div></div>
                <div class="col-md-4"><div class="text-muted small">Mã sản phẩm</div><div class="fw-semibold"><?= e($viewImport['product_code']) ?></div></div>
                <div class="col-md-4"><div class="text-muted small">Trạng thái</div><span class="badge text-bg-<?= statusBadgeClass($viewImport['status']) ?>"><?= e(statusLabel($viewImport['status'])) ?></span></div>
                <div class="col-md-4"><div class="text-muted small">Số lượng nhập</div><div><?= e(formatQty($viewImport['quantity'])) ?></div></div>
                <div class="col-md-4"><div class="text-muted small">Đã chuyển SX</div><div><?= e(formatQty($viewImport['quantity_sent'])) ?></div></div>
                <div class="col-md-4"><div class="text-muted small">Còn lại</div><div><?= e(formatQty((float) $viewImport['quantity'] - (float) $viewImport['quantity_sent'])) ?></div></div>
                <div class="col-12"><div class="text-muted small">Diễn giải</div><div><?= nl2br(e($viewImport['description'])) ?></div></div>
                <div class="col-12"><div class="text-muted small">Ghi chú</div><div><?= nl2br(e($viewImport['note'])) ?></div></div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <form method="get" class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Trạng thái</label>
                            <select name="status" class="form-select">
                                <option value="">Tất cả</option>
                                <?php foreach (['pending' => 'Chờ xử lý', 'partial' => 'Một phần', 'completed' => 'Hoàn thành'] as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3"><label class="form-label">Từ ngày</label><input type="date" name="date_from" class="form-control" value="<?= e($filters['date_from']) ?>"></div>
                        <div class="col-md-3"><label class="form-label">Đến ngày</label><input type="date" name="date_to" class="form-control" value="<?= e($filters['date_to']) ?>"></div>
                        <div class="col-md-3 d-flex gap-2 align-items-end"><button type="submit" class="btn btn-primary flex-fill">Lọc</button><a href="/ntn_erp/modules/production/warehouse_import.php" class="btn btn-outline-secondary">Reset</a></div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Mã phiếu</th>
                                    <th>Ngày nhập</th>
                                    <th>Sản phẩm</th>
                                    <th>Số lượng</th>
                                    <th>Đã chuyển</th>
                                    <th>Trạng thái</th>
                                    <th class="text-end">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$imports): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-4">Chưa có phiếu nhập kho.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($imports as $item): ?>
                                    <tr>
                                        <td class="fw-semibold"><?= e($item['import_no']) ?></td>
                                        <td><?= e(date('d/m/Y', strtotime($item['import_date']))) ?></td>
                                        <td>
                                            <div><?= e($item['product_code']) ?></div>
                                            <div class="text-muted small"><?= e($item['product_name']) ?></div>
                                        </td>
                                        <td><?= e(formatQty($item['quantity'])) ?></td>
                                        <td><?= e(formatQty($item['quantity_sent'])) ?></td>
                                        <td><span class="badge text-bg-<?= statusBadgeClass($item['status']) ?>"><?= e(statusLabel($item['status'])) ?></span></td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm">
                                                <a href="/ntn_erp/modules/production/warehouse_import.php?view=<?= (int) $item['id'] ?>" class="btn btn-outline-secondary">Xem</a>
                                                <?php if ($item['status'] === 'pending'): ?>
                                                    <a href="/ntn_erp/modules/production/warehouse_import.php?edit=<?= (int) $item['id'] ?>" class="btn btn-outline-primary">Sửa</a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white"><h2 class="h5 mb-0"><?= $formData['id'] > 0 ? 'Cập nhật phiếu nhập' : 'Tạo phiếu nhập mới' ?></h2></div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="save_import">
                        <input type="hidden" name="id" value="<?= (int) $formData['id'] ?>">
                        <div class="col-12">
                            <label class="form-label">Ngày nhập <span class="text-danger">*</span></label>
                            <input type="date" name="import_date" class="form-control" value="<?= e($formData['import_date']) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Mã sản phẩm <span class="text-danger">*</span></label>
                            <select name="product_code_id" class="form-select" required>
                                <option value="">Chọn sản phẩm</option>
                                <?php foreach ($productCodes as $product): ?>
                                    <option value="<?= (int) $product['id'] ?>" <?= (int) $formData['product_code_id'] === (int) $product['id'] ? 'selected' : '' ?>><?= e($product['product_code'] . ' - ' . $product['description']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Diễn giải</label>
                            <textarea name="description" class="form-control" rows="3"><?= e($formData['description']) ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Số lượng <span class="text-danger">*</span></label>
                            <input type="number" min="0" step="0.01" name="quantity" class="form-control" value="<?= e((string) $formData['quantity']) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Ghi chú</label>
                            <textarea name="note" class="form-control" rows="2"><?= e($formData['note']) ?></textarea>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-fill">Lưu phiếu nhập</button>
                            <a href="/ntn_erp/modules/production/warehouse_import.php" class="btn btn-outline-secondary">Hủy</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
