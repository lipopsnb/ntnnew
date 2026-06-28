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
    function adjustWarehouseStock(PDO $pdo, int $productId, float $completed, float $defect): void
    {
        $stmt = $pdo->prepare('SELECT id, qty_completed, qty_defect FROM warehouse_stock WHERE product_code_id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $pdo->prepare('UPDATE warehouse_stock SET qty_completed = ?, qty_defect = ?, updated_at = NOW() WHERE id = ?')->execute([
                max(0, (float) $row['qty_completed'] + $completed),
                max(0, (float) $row['qty_defect'] + $defect),
                $row['id'],
            ]);
        } else {
            $pdo->prepare('INSERT INTO warehouse_stock (product_code_id, qty_pending, qty_completed, qty_defect, updated_at) VALUES (?, 0, ?, ?, NOW())')->execute([$productId, max(0, $completed), max(0, $defect)]);
        }
    }
}
if (!function_exists('adjustProductionStock')) {
    function adjustProductionStock(PDO $pdo, int $productId, string $stockDate, float $pending, float $completed, float $defect): void
    {
        $sameDate = $pdo->prepare('SELECT * FROM production_stock WHERE product_code_id = ? AND stock_date = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $sameDate->execute([$productId, $stockDate]);
        $row = $sameDate->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $latest = $pdo->prepare('SELECT * FROM production_stock WHERE product_code_id = ? ORDER BY stock_date DESC, id DESC LIMIT 1 FOR UPDATE');
            $latest->execute([$productId]);
            $prev = $latest->fetch(PDO::FETCH_ASSOC) ?: ['qty_pending' => 0, 'qty_completed' => 0, 'qty_defect' => 0];
            $pdo->prepare('INSERT INTO production_stock (product_code_id, stock_date, qty_pending, qty_completed, qty_defect, updated_at) VALUES (?, ?, ?, ?, ?, NOW())')->execute([$productId, $stockDate, $prev['qty_pending'], $prev['qty_completed'], $prev['qty_defect']]);
            $sameDate->execute([$productId, $stockDate]);
            $row = $sameDate->fetch(PDO::FETCH_ASSOC);
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_output') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc đã hết hạn.');
        header('Location: /ntn_erp/modules/production/output.php');
        exit;
    }

    $productionReceiptId = (int) ($_POST['production_receipt_id'] ?? 0);
    $outputDate = trim((string) ($_POST['output_date'] ?? date('Y-m-d')));
    $description = trim((string) ($_POST['description'] ?? ''));
    $quantityCompleted = (float) ($_POST['quantity_completed'] ?? 0);
    $quantityDefect = (float) ($_POST['quantity_defect'] ?? 0);
    $quantityDelivered = (float) ($_POST['quantity_delivered'] ?? 0);
    $note = trim((string) ($_POST['note'] ?? ''));
    $userId = (int) (currentUser()['id'] ?? 0);

    if ($productionReceiptId <= 0 || $outputDate === '' || ($quantityCompleted + $quantityDefect) <= 0) {
        $errors[] = 'Vui lòng chọn phiếu nhận và nhập số lượng thành phẩm/lỗi.';
    }
    if ($quantityDelivered < 0 || $quantityDelivered > $quantityCompleted) {
        $errors[] = 'Số lượng giao ngay không được lớn hơn số lượng hoàn thành.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $receiptStmt = $pdo->prepare('SELECT pr.*, COALESCE((SELECT SUM(po.quantity_completed + po.quantity_defect) FROM production_outputs po WHERE po.production_receipt_id = pr.id), 0) AS processed_qty FROM production_receipts pr WHERE pr.id = ? FOR UPDATE');
            $receiptStmt->execute([$productionReceiptId]);
            $receipt = $receiptStmt->fetch(PDO::FETCH_ASSOC);
            if (!$receipt) {
                throw new RuntimeException('Phiếu nhận sản xuất không tồn tại.');
            }
            $remaining = (float) $receipt['quantity_received'] - (float) $receipt['processed_qty'];
            $processedNow = $quantityCompleted + $quantityDefect;
            if ($processedNow > $remaining + 0.00001) {
                throw new RuntimeException('Số lượng xử lý vượt quá số lượng đang chờ sản xuất.');
            }

            $outputNo = nextDocCode($pdo, 'OUT', $outputDate);
            $insert = $pdo->prepare('INSERT INTO production_outputs (output_no, output_date, production_receipt_id, product_code_id, description, quantity_completed, quantity_defect, quantity_delivered, note, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $insert->execute([$outputNo, $outputDate, $productionReceiptId, $receipt['product_code_id'], $description !== '' ? $description : $receipt['description'], $quantityCompleted, $quantityDefect, $quantityDelivered, $note, $userId ?: null]);
            $outputId = (int) $pdo->lastInsertId();

            adjustProductionStock($pdo, (int) $receipt['product_code_id'], $outputDate, -$processedNow, $quantityCompleted, $quantityDefect);
            $stockCompleted = $quantityCompleted - $quantityDelivered;
            adjustWarehouseStock($pdo, (int) $receipt['product_code_id'], $stockCompleted, $quantityDefect);
            if ($stockCompleted > 0) {
                addWarehouseStockLog($pdo, (int) $receipt['product_code_id'], $outputDate, 'output_completed', 'completed', $stockCompleted, 'production_outputs', $outputId, 'Nhập kho thành phẩm từ sản xuất', $userId ?: null);
            }
            if ($quantityDefect > 0) {
                addWarehouseStockLog($pdo, (int) $receipt['product_code_id'], $outputDate, 'output_defect', 'defect', $quantityDefect, 'production_outputs', $outputId, 'Ghi nhận hàng lỗi từ sản xuất', $userId ?: null);
            }

            $pdo->commit();
            setFlash('success', 'Đã tạo phiếu xuất thành phẩm ' . $outputNo . '.');
            header('Location: /ntn_erp/modules/production/output.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }
}

$availableReceipts = $pdo->query("SELECT pr.id, pr.receipt_no, pr.receipt_date, pr.product_code_id, pr.description, pr.quantity_received, pc.product_code, pc.description AS product_name, COALESCE(SUM(po.quantity_completed + po.quantity_defect), 0) AS qty_processed, pr.quantity_received - COALESCE(SUM(po.quantity_completed + po.quantity_defect), 0) AS qty_remaining FROM production_receipts pr INNER JOIN product_codes pc ON pc.id = pr.product_code_id LEFT JOIN production_outputs po ON po.production_receipt_id = pr.id GROUP BY pr.id HAVING qty_remaining > 0 ORDER BY pr.receipt_date DESC, pr.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$receiptsMap = [];
foreach ($availableReceipts as $item) {
    $receiptsMap[$item['id']] = $item;
}
$outputs = $pdo->query("SELECT po.*, pr.receipt_no, pc.product_code, pc.description AS product_name FROM production_outputs po INNER JOIN production_receipts pr ON pr.id = po.production_receipt_id INNER JOIN product_codes pc ON pc.id = po.product_code_id ORDER BY po.output_date DESC, po.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$flash = getFlash();
$csrfToken = generateCSRF();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Xuất thành phẩm</h1>
            <p class="text-muted mb-0">Ghi nhận thành phẩm, hàng lỗi và số lượng giao ngay từ sản xuất.</p>
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
                                <th>Ngày xuất</th>
                                <th>Phiếu nhận</th>
                                <th>Sản phẩm</th>
                                <th>Hoàn thành</th>
                                <th>Lỗi</th>
                                <th>Giao ngay</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$outputs): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">Chưa có phiếu xuất thành phẩm.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($outputs as $output): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($output['output_no']) ?></td>
                                    <td><?= e(date('d/m/Y', strtotime($output['output_date']))) ?></td>
                                    <td><?= e($output['receipt_no']) ?></td>
                                    <td><div><?= e($output['product_code']) ?></div><div class="text-muted small"><?= e($output['product_name']) ?></div></td>
                                    <td><?= e(formatQty($output['quantity_completed'])) ?></td>
                                    <td><?= e(formatQty($output['quantity_defect'])) ?></td>
                                    <td><?= e(formatQty($output['quantity_delivered'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white"><h2 class="h5 mb-0">Tạo phiếu xuất</h2></div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="save_output">
                        <div class="col-12">
                            <label class="form-label">Phiếu nhận SX <span class="text-danger">*</span></label>
                            <select name="production_receipt_id" class="form-select" id="receiptSelect" required>
                                <option value="">Chọn phiếu nhận</option>
                                <?php foreach ($availableReceipts as $item): ?>
                                    <option value="<?= (int) $item['id'] ?>"><?= e($item['receipt_no'] . ' - ' . $item['product_code'] . ' - Còn ' . formatQty($item['qty_remaining'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Sản phẩm</label>
                            <input type="text" class="form-control" id="receiptProduct" readonly>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Diễn giải</label>
                            <textarea name="description" id="outputDescription" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6"><label class="form-label">Ngày xuất <span class="text-danger">*</span></label><input type="date" name="output_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" required></div>
                        <div class="col-md-6"><label class="form-label">SL còn lại</label><input type="text" class="form-control" id="remainingQty" readonly></div>
                        <div class="col-md-4"><label class="form-label">SL hoàn thành</label><input type="number" min="0" step="0.01" name="quantity_completed" class="form-control" value="0" required></div>
                        <div class="col-md-4"><label class="form-label">SL lỗi</label><input type="number" min="0" step="0.01" name="quantity_defect" class="form-control" value="0" required></div>
                        <div class="col-md-4"><label class="form-label">SL giao ngay</label><input type="number" min="0" step="0.01" name="quantity_delivered" class="form-control" value="0"></div>
                        <div class="col-12"><label class="form-label">Ghi chú</label><textarea name="note" class="form-control" rows="2"></textarea></div>
                        <div class="col-12 d-flex gap-2"><button type="submit" class="btn btn-primary flex-fill">Lưu phiếu xuất</button><a href="/ntn_erp/modules/production/output.php" class="btn btn-outline-secondary">Hủy</a></div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
const receiptsMap = <?= json_encode($receiptsMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const receiptSelect = document.getElementById('receiptSelect');
const receiptProduct = document.getElementById('receiptProduct');
const outputDescription = document.getElementById('outputDescription');
const remainingQty = document.getElementById('remainingQty');
function syncReceipt() {
    const item = receiptsMap[receiptSelect.value] || null;
    receiptProduct.value = item ? `${item.product_code} - ${item.product_name}` : '';
    outputDescription.value = item ? (item.description || '') : '';
    remainingQty.value = item ? Number(item.qty_remaining).toLocaleString('vi-VN') : '';
}
receiptSelect.addEventListener('change', syncReceipt);
syncReceipt();
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
