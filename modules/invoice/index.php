<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

requireRole(['director', 'accountant']);
$pdo = erp_db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
    $paymentDate = (string) ($_POST['payment_date'] ?? date('Y-m-d'));
    $amount = erp_to_decimal($_POST['amount'] ?? 0);
    $method = trim((string) ($_POST['method'] ?? '')); 
    $reference = trim((string) ($_POST['reference'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));

    if (!erp_validate_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'CSRF token không hợp lệ.';
    }
    if ($invoiceId <= 0 || $amount <= 0 || $paymentDate === '' || $method === '') {
        $errors[] = 'Vui lòng nhập đầy đủ thông tin thanh toán.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO invoice_payments (invoice_id, payment_date, amount, method, reference, note, paid_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([$invoiceId, $paymentDate, $amount, $method, $reference, $note, erp_current_username()]);
            $invoiceStmt = $pdo->prepare('SELECT total_amount, COALESCE(paid_amount, 0) AS paid_amount FROM invoices WHERE id = ? FOR UPDATE');
            $invoiceStmt->execute([$invoiceId]);
            $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
            $newPaid = (float) $invoice['paid_amount'] + $amount;
            $status = $newPaid <= 0 ? 'unpaid' : ($newPaid + 0.0001 >= (float) $invoice['total_amount'] ? 'paid' : 'partial');
            $updateStmt = $pdo->prepare('UPDATE invoices SET paid_amount = ?, status = ?, updated_at = NOW() WHERE id = ?');
            $updateStmt->execute([$newPaid, $status, $invoiceId]);
            $pdo->commit();
            erp_flash('success', 'Đã ghi nhận thanh toán.');
            erp_redirect(erp_url('modules/invoice/index.php'));
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Không thể ghi nhận thanh toán: ' . $throwable->getMessage();
        }
    }
}

$filters = [
    'customer_id' => (int) ($_GET['customer_id'] ?? 0),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'month' => trim((string) ($_GET['month'] ?? date('Y-m'))),
];
$where = ['1=1'];
$params = [];
if ($filters['customer_id'] > 0) { $where[] = 'i.customer_id = ?'; $params[] = $filters['customer_id']; }
if ($filters['status'] !== '') { $where[] = 'i.status = ?'; $params[] = $filters['status']; }
if ($filters['month'] !== '') { $where[] = "DATE_FORMAT(i.invoice_date, '%Y-%m') = ?"; $params[] = $filters['month']; }

$summary = $pdo->query('SELECT COALESCE(SUM(total_amount), 0) AS total_debt, COALESCE(SUM(paid_amount), 0) AS total_paid, COALESCE(SUM(total_amount - paid_amount), 0) AS total_remaining FROM invoices')->fetch(PDO::FETCH_ASSOC);
$sql = 'SELECT i.*, c.name AS customer_name FROM invoices i INNER JOIN customers c ON c.id = i.customer_id WHERE ' . implode(' AND ', $where) . ' ORDER BY i.invoice_date DESC, i.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
$customers = $pdo->query('SELECT id, code, name FROM customers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$csrfToken = erp_csrf_token();
$flashes = erp_pull_flashes();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <?php erp_render_breadcrumb([
        ['label' => 'Tổng quan', 'url' => erp_url('dashboard.php')],
        ['label' => 'Hóa đơn'],
    ]); ?>

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3"><div><h1 class="h3 mb-1">Danh sách hóa đơn</h1><p class="text-muted mb-0">Theo dõi công nợ, trạng thái thu tiền và lịch sử thanh toán khách hàng.</p></div><a class="btn btn-primary" href="<?= erp_h(erp_url('modules/invoice/create.php')) ?>"><i class="fa-solid fa-plus me-2"></i>Tạo hóa đơn</a></div>

    <?php foreach ($flashes as $flash): ?><div class="alert alert-<?= erp_h($flash['type']) ?> alert-dismissible fade show" role="alert"><?= erp_h($flash['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endforeach; ?>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= erp_h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card shadow-sm border-0"><div class="card-body"><div class="text-muted small">Tổng công nợ</div><div class="h4 mb-0"><?= erp_h(erp_format_vnd($summary['total_debt'])) ?></div></div></div></div>
        <div class="col-md-4"><div class="card shadow-sm border-0"><div class="card-body"><div class="text-muted small">Đã thu</div><div class="h4 mb-0 text-success"><?= erp_h(erp_format_vnd($summary['total_paid'])) ?></div></div></div></div>
        <div class="col-md-4"><div class="card shadow-sm border-0"><div class="card-body"><div class="text-muted small">Còn lại</div><div class="h4 mb-0 text-danger"><?= erp_h(erp_format_vnd($summary['total_remaining'])) ?></div></div></div></div>
    </div>

    <div class="card shadow-sm border-0 mb-4"><div class="card-body"><form class="row g-3" method="get"><div class="col-md-4"><label class="form-label">Khách hàng</label><select class="form-select" name="customer_id"><option value="0">Tất cả</option><?php foreach ($customers as $customer): ?><option value="<?= (int) $customer['id'] ?>" <?= $filters['customer_id'] === (int) $customer['id'] ? 'selected' : '' ?>><?= erp_h($customer['code'] . ' - ' . $customer['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Trạng thái</label><select class="form-select" name="status"><option value="">Tất cả</option><?php foreach (['unpaid' => 'Chưa thanh toán', 'partial' => 'Thanh toán một phần', 'paid' => 'Đã thanh toán', 'overdue' => 'Quá hạn'] as $value => $label): ?><option value="<?= erp_h($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= erp_h($label) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Tháng</label><input type="month" class="form-control" name="month" value="<?= erp_h($filters['month']) ?>"></div><div class="col-md-2 align-self-end d-flex gap-2"><button class="btn btn-outline-primary flex-fill" type="submit">Lọc</button><a class="btn btn-outline-secondary" href="<?= erp_h(erp_url('modules/invoice/index.php')) ?>">Reset</a></div></form></div></div>

    <div class="card shadow-sm border-0">
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Mã HĐ</th><th>Khách hàng</th><th>Ngày HĐ</th><th>Tổng tiền</th><th>Đã thu</th><th>Còn lại</th><th>Trạng thái</th><th>Hạn TT</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php if (!$invoices): ?><tr><td colspan="9" class="text-center text-muted py-4">Chưa có hóa đơn.</td></tr><?php endif; ?>
                <?php foreach ($invoices as $invoice): ?>
                    <?php $remaining = (float) $invoice['total_amount'] - (float) $invoice['paid_amount']; ?>
                    <tr>
                        <td class="fw-semibold"><?= erp_h($invoice['invoice_code']) ?></td>
                        <td><?= erp_h($invoice['customer_name']) ?></td>
                        <td><?= erp_h(erp_format_date($invoice['invoice_date'])) ?></td>
                        <td class="text-nowrap"><?= erp_h(erp_format_vnd($invoice['total_amount'])) ?></td>
                        <td class="text-nowrap"><?= erp_h(erp_format_vnd($invoice['paid_amount'])) ?></td>
                        <td class="text-nowrap"><?= erp_h(erp_format_vnd($remaining)) ?></td>
                        <td><span class="badge text-bg-<?= erp_h(erp_status_badge_class($invoice['status'])) ?>"><?= erp_h(erp_status_label($invoice['status'])) ?></span></td>
                        <td><?= erp_h(erp_format_date($invoice['due_date'])) ?></td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#viewInvoiceModal<?= (int) $invoice['id'] ?>">Xem</button>
                                <button class="btn btn-outline-success payment-trigger" type="button" data-id="<?= (int) $invoice['id'] ?>" data-code="<?= erp_h($invoice['invoice_code']) ?>" data-remaining="<?= max(0, $remaining) ?>" data-bs-toggle="modal" data-bs-target="#paymentModal">Ghi nhận thanh toán</button>
                            </div>
                        </td>
                    </tr>
                    <div class="modal fade" id="viewInvoiceModal<?= (int) $invoice['id'] ?>" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Hóa đơn <?= erp_h($invoice['invoice_code']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-6"><div class="text-muted small">Khách hàng</div><div class="fw-semibold"><?= erp_h($invoice['customer_name']) ?></div></div><div class="col-md-3"><div class="text-muted small">Ngày HĐ</div><div><?= erp_h(erp_format_date($invoice['invoice_date'])) ?></div></div><div class="col-md-3"><div class="text-muted small">Hạn thanh toán</div><div><?= erp_h(erp_format_date($invoice['due_date'])) ?></div></div><div class="col-md-4"><div class="text-muted small">Subtotal</div><div><?= erp_h(erp_format_vnd($invoice['subtotal'] ?? 0)) ?></div></div><div class="col-md-4"><div class="text-muted small">Thuế</div><div><?= erp_h((string) ($invoice['tax_rate'] ?? 0)) ?>%</div></div><div class="col-md-4"><div class="text-muted small">Tổng cộng</div><div class="fw-semibold"><?= erp_h(erp_format_vnd($invoice['total_amount'])) ?></div></div><div class="col-12"><div class="text-muted small">Ghi chú</div><div><?= nl2br(erp_h($invoice['note'])) ?></div></div></div></div></div></div></div>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post"><div class="modal-header"><h5 class="modal-title">Ghi nhận thanh toán <span id="paymentInvoiceCode"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body row g-3"><input type="hidden" name="csrf_token" value="<?= erp_h($csrfToken) ?>"><input type="hidden" name="action" value="record_payment"><input type="hidden" name="invoice_id" id="paymentInvoiceId"><div class="col-md-6"><label class="form-label">Ngày thanh toán</label><input type="date" class="form-control" name="payment_date" value="<?= date('Y-m-d') ?>" required></div><div class="col-md-6"><label class="form-label">Số tiền</label><input type="number" min="0" step="0.01" class="form-control" name="amount" id="paymentAmount" required></div><div class="col-md-6"><label class="form-label">Phương thức</label><select class="form-select" name="method" required><option value="">Chọn phương thức</option><option value="Tiền mặt">Tiền mặt</option><option value="Chuyển khoản">Chuyển khoản</option></select></div><div class="col-md-6"><label class="form-label">Tham chiếu</label><input type="text" class="form-control" name="reference"></div><div class="col-12"><label class="form-label">Ghi chú</label><textarea class="form-control" name="note" rows="3"></textarea></div></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Đóng</button><button class="btn btn-primary" type="submit">Lưu thanh toán</button></div></form></div></div>
</div>

<script>
document.querySelectorAll('.payment-trigger').forEach((button) => {
    button.addEventListener('click', () => {
        document.getElementById('paymentInvoiceId').value = button.dataset.id || '';
        document.getElementById('paymentInvoiceCode').textContent = button.dataset.code || '';
        document.getElementById('paymentAmount').value = button.dataset.remaining || '0';
    });
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
