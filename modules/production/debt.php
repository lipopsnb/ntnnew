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
if (!function_exists('debtBadgeClass')) {
    function debtBadgeClass(string $status): string
    {
        return match ($status) {
            'paid' => 'success',
            'partial' => 'warning',
            default => 'danger',
        };
    }
}
if (!function_exists('debtBadgeLabel')) {
    function debtBadgeLabel(string $status): string
    {
        return match ($status) {
            'paid' => 'Đã thanh toán',
            'partial' => 'Thanh toán một phần',
            default => 'Chưa thanh toán',
        };
    }
}

$errors = [];
$flash = getFlash();
$csrfToken = generateCSRF();
$userId = (int) (currentUser()['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc đã hết hạn.');
        header('Location: /ntn_erp/modules/production/debt.php');
        exit;
    }

    $debtId = (int) ($_POST['debt_id'] ?? 0);
    $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
    $paymentDate = trim((string) ($_POST['payment_date'] ?? date('Y-m-d')));
    $amount = (float) ($_POST['amount'] ?? 0);
    $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'cash'));
    $referenceNo = trim((string) ($_POST['reference_no'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));

    if ($debtId <= 0 || $invoiceId <= 0 || $paymentDate === '' || $amount <= 0) {
        $errors[] = 'Vui lòng nhập đầy đủ thông tin thanh toán.';
    }
    if (!in_array($paymentMethod, ['cash', 'transfer', 'other'], true)) {
        $errors[] = 'Phương thức thanh toán không hợp lệ.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM debt_tracking WHERE id = ? AND invoice_id = ? FOR UPDATE');
            $stmt->execute([$debtId, $invoiceId]);
            $debt = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$debt) {
                throw new RuntimeException('Khoản công nợ không tồn tại.');
            }
            if ($amount > (float) $debt['remaining_amount'] + 0.00001) {
                throw new RuntimeException('Số tiền thanh toán vượt quá số còn lại.');
            }

            $pdo->prepare('INSERT INTO debt_payments (debt_id, invoice_id, payment_date, amount, payment_method, reference_no, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
                ->execute([$debtId, $invoiceId, $paymentDate, $amount, $paymentMethod, $referenceNo, $note, $userId ?: null]);

            $paidAmount = (float) $debt['paid_amount'] + $amount;
            $remainingAmount = max(0, (float) $debt['remaining_amount'] - $amount);
            $status = $remainingAmount <= 0.00001 ? 'paid' : ($paidAmount > 0 ? 'partial' : 'unpaid');
            $pdo->prepare('UPDATE debt_tracking SET paid_amount = ?, remaining_amount = ?, status = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$paidAmount, $remainingAmount, $status, $debtId]);
            $pdo->commit();
            setFlash('success', 'Đã ghi nhận thanh toán công nợ.');
            header('Location: /ntn_erp/modules/production/debt.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }
}

$debts = $pdo->query("SELECT dt.*, c.customer_code, c.customer_name, i.invoice_no, i.status AS invoice_status FROM debt_tracking dt INNER JOIN customers c ON c.id = dt.customer_id INNER JOIN invoices i ON i.id = dt.invoice_id WHERE i.status <> 'cancelled' ORDER BY dt.due_date ASC, dt.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$paymentsMap = [];
if ($debts) {
    $ids = array_column($debts, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM debt_payments WHERE debt_id IN ($placeholders) ORDER BY payment_date DESC, id DESC");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $paymentsMap[$row['debt_id']][] = $row;
    }
}

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Quản lý công nợ</h1>
            <p class="text-muted mb-0">Theo dõi khoản phải thu, lịch sử thanh toán và cảnh báo quá hạn.</p>
        </div>
    </div>

    <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert"><?= e($flash['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Khách hàng</th>
                        <th>Hóa đơn</th>
                        <th>Tổng tiền</th>
                        <th>Đã thu</th>
                        <th>Còn lại</th>
                        <th>Hạn thanh toán</th>
                        <th>Trạng thái</th>
                        <th class="text-end">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$debts): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">Chưa có dữ liệu công nợ.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($debts as $debt): ?>
                        <?php $isOverdue = $debt['status'] !== 'paid' && strtotime($debt['due_date']) < strtotime(date('Y-m-d')); ?>
                        <tr class="<?= $isOverdue ? 'table-danger' : '' ?>">
                            <td><?= e($debt['customer_code'] . ' - ' . $debt['customer_name']) ?></td>
                            <td><?= e($debt['invoice_no']) ?></td>
                            <td><?= e(formatCurrency($debt['total_amount'])) ?></td>
                            <td><?= e(formatCurrency($debt['paid_amount'])) ?></td>
                            <td class="fw-semibold"><?= e(formatCurrency($debt['remaining_amount'])) ?></td>
                            <td><?= e(date('d/m/Y', strtotime($debt['due_date']))) ?></td>
                            <td><span class="badge text-bg-<?= debtBadgeClass($debt['status']) ?>"><?= e(debtBadgeLabel($debt['status'])) ?></span></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-success payment-trigger" type="button" data-bs-toggle="modal" data-bs-target="#paymentModal" data-debt-id="<?= (int) $debt['id'] ?>" data-invoice-id="<?= (int) $debt['invoice_id'] ?>" data-invoice-no="<?= e($debt['invoice_no']) ?>" data-remaining="<?= (float) $debt['remaining_amount'] ?>">Thu tiền</button>
                                    <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#history<?= (int) $debt['id'] ?>">Lịch sử</button>
                                </div>
                            </td>
                        </tr>
                        <tr class="collapse" id="history<?= (int) $debt['id'] ?>">
                            <td colspan="8" class="bg-light">
                                <div class="p-2">
                                    <h6 class="mb-2">Lịch sử thanh toán</h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead><tr><th>Ngày</th><th>Số tiền</th><th>Phương thức</th><th>Tham chiếu</th><th>Ghi chú</th></tr></thead>
                                            <tbody>
                                                <?php if (empty($paymentsMap[$debt['id']])): ?>
                                                    <tr><td colspan="5" class="text-center text-muted">Chưa có thanh toán.</td></tr>
                                                <?php else: ?>
                                                    <?php foreach ($paymentsMap[$debt['id']] as $payment): ?>
                                                        <tr>
                                                            <td><?= e(date('d/m/Y', strtotime($payment['payment_date']))) ?></td>
                                                            <td><?= e(formatCurrency($payment['amount'])) ?></td>
                                                            <td><?= e($payment['payment_method']) ?></td>
                                                            <td><?= e($payment['reference_no']) ?></td>
                                                            <td><?= e($payment['note']) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Ghi nhận thanh toán <span id="paymentInvoiceNo"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="record_payment">
                    <input type="hidden" name="debt_id" id="paymentDebtId">
                    <input type="hidden" name="invoice_id" id="paymentInvoiceId">
                    <div class="col-md-6"><label class="form-label">Ngày thanh toán</label><input type="date" name="payment_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" required></div>
                    <div class="col-md-6"><label class="form-label">Số tiền</label><input type="number" min="0" step="0.01" name="amount" id="paymentAmount" class="form-control" required></div>
                    <div class="col-md-6"><label class="form-label">Phương thức</label><select name="payment_method" class="form-select"><option value="cash">Tiền mặt</option><option value="transfer">Chuyển khoản</option><option value="other">Khác</option></select></div>
                    <div class="col-md-6"><label class="form-label">Số tham chiếu</label><input type="text" name="reference_no" class="form-control"></div>
                    <div class="col-12"><label class="form-label">Ghi chú</label><textarea name="note" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Đóng</button>
                    <button type="submit" class="btn btn-primary">Lưu thanh toán</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.querySelectorAll('.payment-trigger').forEach(button => {
    button.addEventListener('click', () => {
        document.getElementById('paymentDebtId').value = button.dataset.debtId || '';
        document.getElementById('paymentInvoiceId').value = button.dataset.invoiceId || '';
        document.getElementById('paymentInvoiceNo').textContent = button.dataset.invoiceNo || '';
        document.getElementById('paymentAmount').value = button.dataset.remaining || '0';
        document.getElementById('paymentAmount').max = button.dataset.remaining || '0';
    });
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
