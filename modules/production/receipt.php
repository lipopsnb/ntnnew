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
            $pdo->prepare('UPDATE document_sequences SET last_seq = ? WHERE id = ?')->execute([$next, $row['id']]);
        } else {
            $pdo->prepare('INSERT INTO document_sequences (doc_type, doc_date, last_seq) VALUES (?, ?, ?)')->execute([$prefix, $dateKey, $next]);
        }
        return sprintf('%s-%s-%03d', $prefix, date('Ymd', strtotime($dateKey)), $next);
    }
}
if (!function_exists('adjustWarehouseStock')) {
    function adjustWarehouseStock(PDO $pdo, int $productId, float $pending): void
    {
        $stmt = $pdo->prepare('SELECT id, qty_pending FROM warehouse_stock WHERE product_code_id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $pdo->prepare('UPDATE warehouse_stock SET qty_pending = ?, updated_at = NOW() WHERE id = ?')->execute([max(0, (float) $row['qty_pending'] + $pending), $row['id']]);
        } else {
            $pdo->prepare('INSERT INTO warehouse_stock (product_code_id, qty_pending, qty_completed, qty_defect, updated_at) VALUES (?, ?, 0, 0, NOW())')->execute([$productId, max(0, $pending)]);
        }
    }
}
if (!function_exists('adjustProductionStock')) {
    function adjustProductionStock(PDO $pdo, int $productId, string $stockDate, float $pending, float $completed = 0, float $defect = 0): void
    {
        $sameDate = $pdo->prepare('SELECT * FROM production_stock WHERE product_code_id = ? AND stock_date = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $sameDate->execute([$productId, $stockDate]);
        $row = $sameDate->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $latest = $pdo->prepare('SELECT * FROM production_stock WHERE product_code_id = ? ORDER BY stock_date DESC, id DESC LIMIT 1 FOR UPDATE');
            $latest->execute([$productId]);
            $prev = $latest->fetch(PDO::FETCH_ASSOC) ?: ['qty_pending' => 0, 'qty_completed' => 0, 'qty_defect' => 0];
            $pdo->prepare('INSERT INTO production_stock (product_code_id, stock_date, qty_pending, qty_completed, qty_defect, updated_at) VALUES (?, ?, ?, ?, ?, NOW())')->execute([$productId, $stockDate, $prev['qty_pending'], $prev['qty_completed'], $prev['qty_defect']]);
            $rowId = (int) $pdo->lastInsertId();
            $sameDate->execute([$productId, $stockDate]);
            $row = $sameDate->fetch(PDO::FETCH_ASSOC) ?: ['id' => $rowId, 'qty_pending' => $prev['qty_pending'], 'qty_completed' => $prev['qty_completed'], 'qty_defect' => $prev['qty_defect']];
        }
        $pdo->prepare('UPDATE production_stock SET qty_pending = ?, qty_completed = ?, qty_defect = ?, updated_at = NOW() WHERE id = ?')->execute([
            max(0, (float) $row['qty_pending'] + $pending),
            max(0, (float) $row['qty_completed'] + $completed),
            max(0, (float) $row['qty_defect'] + $defect),
            $row['id'],
        ]);
    }
}
if (!function_exists('addWarehouseStockLog')) {
    function addWarehouseStockLog(PDO $pdo, int $productId, string $date, string $txnType, string $stockType, float $qtyChange, string $refTable, int $refId, string $note, ?int $createdBy): void
    {
        $stmt = $pdo->prepare('INSERT INTO warehouse_stock_log (product_code_id, log_date, txn_type, stock_type, qty_change, ref_table, ref_id, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$productId, $date, $txnType, $stockType, $qtyChange, $refTable, $refId, $note, $createdBy]);
    }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_receipt') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc đã hết hạn.');
        header('Location: /ntn_erp/modules/production/receipt.php');
        exit;
    }

    $warehouseImportId = (int) ($_POST['warehouse_import_id'] ?? 0);
    $receiptDate = trim((string) ($_POST['receipt_date'] ?? date('Y-m-d')));
    $quantityReceived = (float) ($_POST['quantity_received'] ?? 0);
    $note = trim((string) ($_POST['note'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $userId = (int) (currentUser()['id'] ?? 0);

    if ($warehouseImportId <= 0 || $receiptDate === '' || $quantityReceived <= 0) {
        $errors[] = 'Vui lòng chọn phiếu nhập, ngày nhận và số lượng nhận.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $importStmt = $pdo->prepare('SELECT * FROM warehouse_imports WHERE id = ? FOR UPDATE');
            $importStmt->execute([$warehouseImportId]);
            $import = $importStmt->fetch(PDO::FETCH_ASSOC);
            if (!$import) {
                throw new RuntimeException('Phiếu nhập kho không tồn tại.');
            }
            $remaining = (float) $import['quantity'] - (float) $import['quantity_sent'];
            if ($quantityReceived > $remaining) {
                throw new RuntimeException('Số lượng nhận vượt quá số lượng còn lại của phiếu nhập.');
            }

            $receiptNo = nextDocCode($pdo, 'PR', $receiptDate);
            $insert = $pdo->prepare('INSERT INTO production_receipts (receipt_no, receipt_date, warehouse_import_id, product_code_id, description, quantity_received, note, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $insert->execute([$receiptNo, $receiptDate, $warehouseImportId, $import['product_code_id'], $description !== '' ? $description : $import['description'], $quantityReceived, $note, $userId ?: null]);
            $receiptId = (int) $pdo->lastInsertId();

            $newSent = (float) $import['quantity_sent'] + $quantityReceived;
            $newStatus = $newSent + 0.00001 >= (float) $import['quantity'] ? 'completed' : 'partial';
            $pdo->prepare('UPDATE warehouse_imports SET quantity_sent = ?, status = ?, updated_at = NOW() WHERE id = ?')->execute([$newSent, $newStatus, $warehouseImportId]);
            addWarehouseStockLog($pdo, (int) $import['product_code_id'], $receiptDate, 'send_to_prod', 'pending', -$quantityReceived, 'production_receipts', $receiptId, 'Chuyển vào sản xuất', $userId ?: null);
            adjustWarehouseStock($pdo, (int) $import['product_code_id'], -$quantityReceived);
            adjustProductionStock($pdo, (int) $import['product_code_id'], $receiptDate, $quantityReceived);

            $pdo->commit();
            setFlash('success', 'Đã tạo phiếu nhận sản xuất ' . $receiptNo . '.');
            header('Location: /ntn_erp/modules/production/receipt.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }
}

$availableImports = $pdo->query("SELECT wi.id, wi.import_no, wi.import_date, wi.product_code_id, wi.description, wi.quantity, wi.quantity_sent, pc.product_code, pc.description AS product_name, (wi.quantity - wi.quantity_sent) AS qty_remaining FROM warehouse_imports wi INNER JOIN product_codes pc ON pc.id = wi.product_code_id WHERE wi.status IN ('pending', 'partial') AND wi.quantity > wi.quantity_sent ORDER BY wi.import_date DESC, wi.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$importsMap = [];
foreach ($availableImports as $item) {
    $importsMap[$item['id']] = $item;
}
$receipts = $pdo->query("SELECT pr.*, wi.import_no, pc.product_code, pc.description AS product_name FROM production_receipts pr INNER JOIN warehouse_imports wi ON wi.id = pr.warehouse_import_id INNER JOIN product_codes pc ON pc.id = pr.product_code_id ORDER BY pr.receipt_date DESC, pr.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$flash = getFlash();
$csrfToken = generateCSRF();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Phiếu nhận sản xuất</h1>
            <p class="text-muted mb-0">Ghi nhận số lượng đã chuyển từ kho chờ sang sản xuất.</p>
        </div>
    </div>

    <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert"><?= e($flash['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card shadow-sm border-0">
                <div class="card-body table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Mã phiếu</th>
                                <th>Ngày nhận</th>
                                <th>Phiếu nhập</th>
                                <th>Sản phẩm</th>
                                <th>Số lượng nhận</th>
                                <th>Ghi chú</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$receipts): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Chưa có phiếu nhận sản xuất.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($receipts as $receipt): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($receipt['receipt_no']) ?></td>
                                    <td><?= e(date('d/m/Y', strtotime($receipt['receipt_date']))) ?></td>
                                    <td><?= e($receipt['import_no']) ?></td>
                                    <td><div><?= e($receipt['product_code']) ?></div><div class="text-muted small"><?= e($receipt['product_name']) ?></div></td>
                                    <td><?= e(formatQty($receipt['quantity_received'])) ?></td>
                                    <td><?= e($receipt['note']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white"><h2 class="h5 mb-0">Tạo phiếu nhận</h2></div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="save_receipt">
                        <div class="col-12">
                            <label class="form-label">Phiếu nhập kho <span class="text-danger">*</span></label>
                            <select name="warehouse_import_id" class="form-select" id="warehouseImportSelect" required>
                                <option value="">Chọn phiếu nhập</option>
                                <?php foreach ($availableImports as $item): ?>
                                    <option value="<?= (int) $item['id'] ?>"><?= e($item['import_no'] . ' - ' . $item['product_code'] . ' - Còn ' . formatQty($item['qty_remaining'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Sản phẩm</label>
                            <input type="text" class="form-control" id="productInfo" readonly>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Diễn giải</label>
                            <textarea name="description" id="receiptDescription" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ngày nhận <span class="text-danger">*</span></label>
                            <input type="date" name="receipt_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Số lượng nhận <span class="text-danger">*</span></label>
                            <input type="number" min="0" step="0.01" name="quantity_received" id="quantityReceived" class="form-control" required>
                            <div class="form-text" id="remainingText">Chọn phiếu nhập để xem số lượng còn lại.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Ghi chú</label>
                            <textarea name="note" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-fill">Lưu phiếu nhận</button>
                            <a href="/ntn_erp/modules/production/receipt.php" class="btn btn-outline-secondary">Hủy</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
const importsMap = <?= json_encode($importsMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const importSelect = document.getElementById('warehouseImportSelect');
const productInfo = document.getElementById('productInfo');
const receiptDescription = document.getElementById('receiptDescription');
const quantityReceived = document.getElementById('quantityReceived');
const remainingText = document.getElementById('remainingText');

function syncImportInfo() {
    const item = importsMap[importSelect.value] || null;
    productInfo.value = item ? `${item.product_code} - ${item.product_name}` : '';
    receiptDescription.value = item ? (item.description || '') : '';
    quantityReceived.max = item ? item.qty_remaining : '';
    remainingText.textContent = item ? `Số lượng còn lại: ${Number(item.qty_remaining).toLocaleString('vi-VN')}` : 'Chọn phiếu nhập để xem số lượng còn lại.';
}
importSelect.addEventListener('change', syncImportInfo);
syncImportInfo();
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
