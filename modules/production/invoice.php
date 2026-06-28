<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireLogin();

requireRole('director', 'accountant', 'manager');
$pdo = getDBConnection();

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('formatCurrency')) {
    function formatCurrency($amount): string
    {
        return number_format((float) $amount, 0, ',', '.') . ' đ';
    }
}
if (!function_exists('invoiceBadgeClass')) {
    function invoiceBadgeClass(string $status): string
    {
        return match ($status) {
            'confirmed' => 'success',
            'cancelled' => 'danger',
            default => 'secondary',
        };
    }
}
if (!function_exists('invoiceBadgeLabel')) {
    function invoiceBadgeLabel(string $status): string
    {
        return match ($status) {
            'confirmed' => 'Đã xác nhận',
            'cancelled' => 'Đã hủy',
            default => 'Nháp',
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
            $pdo->prepare('UPDATE document_sequences SET last_seq = ? WHERE id = ?')->execute([$next, $row['id']]);
        } else {
            $pdo->prepare('INSERT INTO document_sequences (doc_type, doc_date, last_seq) VALUES (?, ?, ?)')->execute([$prefix, $dateKey, $next]);
        }
        return sprintf('%s-%s-%03d', $prefix, date('Ymd', strtotime($dateKey)), $next);
    }
}

$errors = [];
$flash = getFlash();
$csrfToken = generateCSRF();
$userId = (int) (currentUser()['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc đã hết hạn.');
        header('Location: /ntn_erp/modules/production/invoice.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'confirm_invoice') {
        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE invoices SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW(), updated_at = NOW() WHERE id = ? AND status = 'draft'");
        $stmt->execute([$userId ?: null, $invoiceId]);
        setFlash('success', 'Đã xác nhận hóa đơn.');
        header('Location: /ntn_erp/modules/production/invoice.php');
        exit;
    }

    if ($action === 'cancel_invoice') {
        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE invoices SET status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$invoiceId]);
            $dnStmt = $pdo->prepare('SELECT delivery_note_id FROM invoice_delivery_notes WHERE invoice_id = ?');
            $dnStmt->execute([$invoiceId]);
            $deliveryIds = array_column($dnStmt->fetchAll(PDO::FETCH_ASSOC), 'delivery_note_id');
            if ($deliveryIds) {
                $placeholders = implode(',', array_fill(0, count($deliveryIds), '?'));
                $pdo->prepare("UPDATE delivery_notes SET status = 'confirmed', updated_at = NOW() WHERE id IN ($placeholders)")->execute($deliveryIds);
            }
            $pdo->commit();
            setFlash('success', 'Đã hủy hóa đơn.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('danger', 'Không thể hủy hóa đơn: ' . $e->getMessage());
        }
        header('Location: /ntn_erp/modules/production/invoice.php');
        exit;
    }

    if ($action === 'create_invoice') {
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $invoiceDate = trim((string) ($_POST['invoice_date'] ?? date('Y-m-d')));
        $dueDate = trim((string) ($_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days'))));
        $vatRate = (int) ($_POST['vat_rate'] ?? 8);
        $note = trim((string) ($_POST['note'] ?? ''));
        $deliveryIds = array_values(array_filter(array_map('intval', (array) ($_POST['delivery_ids'] ?? []))));

        if ($customerId <= 0 || $invoiceDate === '' || $dueDate === '') {
            $errors[] = 'Vui lòng nhập đầy đủ thông tin hóa đơn.';
        }
        if (!in_array($vatRate, [0, 8, 10], true)) {
            $errors[] = 'Thuế suất chỉ hỗ trợ 0%, 8% hoặc 10%.';
        }
        if (!$deliveryIds) {
            $errors[] = 'Vui lòng chọn ít nhất một phiếu giao hàng.';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $placeholders = implode(',', array_fill(0, count($deliveryIds), '?'));
                $sql = "SELECT dn.* FROM delivery_notes dn WHERE dn.id IN ($placeholders) AND dn.customer_id = ? AND dn.status = 'confirmed' AND NOT EXISTS (SELECT 1 FROM invoice_delivery_notes idn INNER JOIN invoices i ON i.id = idn.invoice_id AND i.status <> 'cancelled' WHERE idn.delivery_note_id = dn.id) FOR UPDATE";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([...$deliveryIds, $customerId]);
                $validNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($validNotes) !== count($deliveryIds)) {
                    throw new RuntimeException('Một hoặc nhiều phiếu giao hàng không hợp lệ hoặc đã lập hóa đơn.');
                }

                $itemStmt = $pdo->prepare("SELECT dni.*, dn.id AS delivery_note_id FROM delivery_note_items dni INNER JOIN delivery_notes dn ON dn.id = dni.delivery_note_id WHERE dn.id IN ($placeholders) ORDER BY dn.id ASC, dni.id ASC");
                $itemStmt->execute($deliveryIds);
                $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
                if (!$items) {
                    throw new RuntimeException('Không có dòng hàng để xuất hóa đơn.');
                }

                $subtotal = 0;
                foreach ($items as $item) {
                    $subtotal += (float) $item['total_price'];
                }
                $vatAmount = round($subtotal * $vatRate / 100, 2);
                $totalAmount = $subtotal + $vatAmount;
                $invoiceNo = nextDocCode($pdo, 'INV', $invoiceDate);
                $deliveryId = count($deliveryIds) === 1 ? $deliveryIds[0] : null;
                $pdo->prepare("INSERT INTO invoices (invoice_no, invoice_date, due_date, customer_id, total_amount, subtotal, vat_rate, vat_amount, note, delivery_id, status, created_by, confirmed_by, confirmed_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, NULL, NULL, NOW(), NOW())")
                    ->execute([$invoiceNo, $invoiceDate, $dueDate, $customerId, $totalAmount, $subtotal, $vatRate, $vatAmount, $note, $deliveryId, $userId ?: null]);
                $invoiceId = (int) $pdo->lastInsertId();

                $linkStmt = $pdo->prepare('INSERT INTO invoice_delivery_notes (invoice_id, delivery_note_id) VALUES (?, ?)');
                foreach ($deliveryIds as $dnId) {
                    $linkStmt->execute([$invoiceId, $dnId]);
                }

                $invoiceItemStmt = $pdo->prepare('INSERT INTO invoice_items (invoice_id, delivery_note_id, delivery_note_item_id, product_code_id, description, quantity, unit_price, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                foreach ($items as $item) {
                    $invoiceItemStmt->execute([$invoiceId, $item['delivery_note_id'], $item['id'], $item['product_code_id'], $item['description'], $item['quantity'], $item['unit_price'], $item['total_price']]);
                }

                $pdo->prepare("INSERT INTO debt_tracking (invoice_id, customer_id, total_amount, paid_amount, remaining_amount, due_date, status, note, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?, 'unpaid', ?, NOW(), NOW())")
                    ->execute([$invoiceId, $customerId, $totalAmount, $totalAmount, $dueDate, $note]);
                $pdo->prepare("UPDATE delivery_notes SET status = 'invoiced', updated_at = NOW() WHERE id IN ($placeholders)")->execute($deliveryIds);

                $pdo->commit();
                setFlash('success', 'Đã tạo hóa đơn ' . $invoiceNo . '.');
                header('Location: /ntn_erp/modules/production/invoice.php');
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

$customers = $pdo->query("SELECT DISTINCT c.id, c.customer_code, c.customer_name FROM customers c INNER JOIN delivery_notes dn ON dn.customer_id = c.id WHERE dn.status = 'confirmed' ORDER BY c.customer_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$eligibleNotes = $pdo->query("SELECT dn.id, dn.delivery_no, dn.delivery_date, dn.customer_id, dn.total_amount, c.customer_code, c.customer_name FROM delivery_notes dn INNER JOIN customers c ON c.id = dn.customer_id WHERE dn.status = 'confirmed' AND NOT EXISTS (SELECT 1 FROM invoice_delivery_notes idn INNER JOIN invoices i ON i.id = idn.invoice_id AND i.status <> 'cancelled' WHERE idn.delivery_note_id = dn.id) ORDER BY dn.delivery_date DESC, dn.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$notesByCustomer = [];
foreach ($eligibleNotes as $note) {
    $notesByCustomer[$note['customer_id']][] = $note;
}
$noteItemMap = [];
if ($eligibleNotes) {
    $ids = array_column($eligibleNotes, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT dni.delivery_note_id, dni.description, dni.quantity, dni.unit_price, dni.total_price, pc.product_code, pc.unit FROM delivery_note_items dni INNER JOIN product_codes pc ON pc.id = dni.product_code_id WHERE dni.delivery_note_id IN ($placeholders) ORDER BY dni.delivery_note_id ASC, dni.id ASC");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $noteItemMap[$row['delivery_note_id']][] = $row;
    }
}
$invoices = $pdo->query("SELECT i.*, c.customer_code, c.customer_name FROM invoices i INNER JOIN customers c ON c.id = i.customer_id ORDER BY i.invoice_date DESC, i.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$invoiceItemSummary = [];
if ($invoices) {
    $ids = array_column($invoices, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT invoice_id, COUNT(*) AS item_count FROM invoice_items WHERE invoice_id IN ($placeholders) GROUP BY invoice_id");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $invoiceItemSummary[$row['invoice_id']] = $row['item_count'];
    }
}

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Hóa đơn bán hàng</h1>
            <p class="text-muted mb-0">Tạo hóa đơn từ phiếu giao đã xác nhận và theo dõi trạng thái phát hành.</p>
        </div>
    </div>

    <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert"><?= e($flash['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Số HĐ</th><th>Ngày HĐ</th><th>Khách hàng</th><th>Subtotal</th><th>VAT</th><th>Tổng tiền</th><th>Trạng thái</th><th class="text-end">Thao tác</th></tr></thead>
                        <tbody>
                            <?php if (!$invoices): ?><tr><td colspan="8" class="text-center text-muted py-4">Chưa có hóa đơn.</td></tr><?php endif; ?>
                            <?php foreach ($invoices as $invoice): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($invoice['invoice_no']) ?></td>
                                    <td><?= e(date('d/m/Y', strtotime($invoice['invoice_date']))) ?></td>
                                    <td>
                                        <div><?= e($invoice['customer_code'] . ' - ' . $invoice['customer_name']) ?></div>
                                        <div class="small text-muted"><?= (int) ($invoiceItemSummary[$invoice['id']] ?? 0) ?> dòng hàng</div>
                                    </td>
                                    <td><?= e(formatCurrency($invoice['subtotal'])) ?></td>
                                    <td><?= e((string) $invoice['vat_rate']) ?>%</td>
                                    <td><?= e(formatCurrency($invoice['total_amount'])) ?></td>
                                    <td><span class="badge text-bg-<?= invoiceBadgeClass($invoice['status']) ?>"><?= e(invoiceBadgeLabel($invoice['status'])) ?></span></td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <?php if ($invoice['status'] === 'draft'): ?>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="confirm_invoice">
                                                    <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-success">Xác nhận</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($invoice['status'] !== 'cancelled'): ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Hủy hóa đơn này?');">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="cancel_invoice">
                                                    <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger">Hủy</button>
                                                </form>
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

        <div class="col-xl-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white"><h2 class="h5 mb-0">Tạo hóa đơn mới</h2></div>
                <div class="card-body">
                    <form method="post" id="invoiceForm" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="create_invoice">
                        <div class="col-12"><label class="form-label">Khách hàng <span class="text-danger">*</span></label><select name="customer_id" id="invoiceCustomer" class="form-select" required><option value="">Chọn khách hàng</option><?php foreach ($customers as $customer): ?><option value="<?= (int) $customer['id'] ?>"><?= e($customer['customer_code'] . ' - ' . $customer['customer_name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-6"><label class="form-label">Ngày hóa đơn <span class="text-danger">*</span></label><input type="date" name="invoice_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" required></div>
                        <div class="col-md-6"><label class="form-label">Hạn thanh toán <span class="text-danger">*</span></label><input type="date" name="due_date" class="form-control" value="<?= e(date('Y-m-d', strtotime('+30 days'))) ?>" required></div>
                        <div class="col-md-6"><label class="form-label">VAT</label><select name="vat_rate" id="vatRate" class="form-select"><option value="0">0%</option><option value="8" selected>8%</option><option value="10">10%</option></select></div>
                        <div class="col-12"><label class="form-label">Ghi chú</label><textarea name="note" class="form-control" rows="2"></textarea></div>
                        <div class="col-12">
                            <label class="form-label">Phiếu giao đã xác nhận</label>
                            <div id="deliveryCheckboxes" class="border rounded p-2 small text-muted">Chọn khách hàng để tải danh sách phiếu giao.</div>
                        </div>
                        <div class="col-12">
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light"><tr><th>Phiếu</th><th>SP</th><th class="text-end">SL</th><th class="text-end">Tiền</th></tr></thead>
                                    <tbody id="invoicePreviewBody"><tr><td colspan="4" class="text-center text-muted py-3">Chưa chọn phiếu giao hàng.</td></tr></tbody>
                                    <tfoot>
                                        <tr><th colspan="3" class="text-end">Subtotal</th><th class="text-end" id="subtotalCell">0 đ</th></tr>
                                        <tr><th colspan="3" class="text-end">VAT</th><th class="text-end" id="vatCell">0 đ</th></tr>
                                        <tr><th colspan="3" class="text-end">Tổng cộng</th><th class="text-end" id="totalCell">0 đ</th></tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                        <div class="col-12 d-flex gap-2"><button type="submit" class="btn btn-primary flex-fill">Lưu hóa đơn</button><a href="/ntn_erp/modules/production/invoice.php" class="btn btn-outline-secondary">Hủy</a></div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
const notesByCustomer = <?= json_encode($notesByCustomer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const noteItemMap = <?= json_encode($noteItemMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const customerSelect = document.getElementById('invoiceCustomer');
const deliveryCheckboxes = document.getElementById('deliveryCheckboxes');
const previewBody = document.getElementById('invoicePreviewBody');
const vatRate = document.getElementById('vatRate');

function money(value) { return new Intl.NumberFormat('vi-VN').format(Number(value || 0)) + ' đ'; }
function renderDeliveryOptions() {
    const customerId = customerSelect.value;
    const notes = notesByCustomer[customerId] || [];
    if (!notes.length) {
        deliveryCheckboxes.innerHTML = '<div class="text-muted">Không có phiếu giao hợp lệ cho khách hàng này.</div>';
        renderPreview();
        return;
    }
    deliveryCheckboxes.innerHTML = notes.map(note => `
        <label class="d-flex align-items-start gap-2 border rounded p-2 mb-2">
            <input type="checkbox" class="form-check-input mt-1 invoice-delivery" name="delivery_ids[]" value="${note.id}">
            <span><strong>${note.delivery_no}</strong><br><span class="text-muted">${note.delivery_date} - ${money(note.total_amount)}</span></span>
        </label>`).join('');
    document.querySelectorAll('.invoice-delivery').forEach(el => el.addEventListener('change', renderPreview));
    renderPreview();
}
function renderPreview() {
    const selected = Array.from(document.querySelectorAll('.invoice-delivery:checked')).map(el => el.value);
    const rows = [];
    let subtotal = 0;
    selected.forEach(id => {
        (noteItemMap[id] || []).forEach(item => {
            subtotal += Number(item.total_price || 0);
            rows.push(`<tr><td>${id}</td><td>${item.product_code} - ${item.description}</td><td class="text-end">${Number(item.quantity).toLocaleString('vi-VN')}</td><td class="text-end">${money(item.total_price)}</td></tr>`);
        });
    });
    previewBody.innerHTML = rows.length ? rows.join('') : '<tr><td colspan="4" class="text-center text-muted py-3">Chưa chọn phiếu giao hàng.</td></tr>';
    const vat = subtotal * Number(vatRate.value || 0) / 100;
    document.getElementById('subtotalCell').textContent = money(subtotal);
    document.getElementById('vatCell').textContent = money(vat);
    document.getElementById('totalCell').textContent = money(subtotal + vat);
}
customerSelect.addEventListener('change', renderDeliveryOptions);
vatRate.addEventListener('change', renderPreview);
renderDeliveryOptions();
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
