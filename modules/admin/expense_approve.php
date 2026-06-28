<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once __DIR__ . '/helpers.php';

requireRole(['director', 'accountant']);

$statusTab = (string) ($_GET['status'] ?? 'submitted');
if (!in_array($statusTab, ['submitted', 'approved', 'paid', 'rejected'], true)) { $statusTab = 'submitted'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensurePostCsrf();
    $action = (string) ($_POST['action'] ?? '');
    $expenseId = (int) ($_POST['expense_id'] ?? 0);

    try {
        if ($action === 'approve' || $action === 'reject') {
            $request = fetchOneSafe($pdo, 'SELECT id, status FROM expense_requests WHERE id = :id LIMIT 1', ['id' => $expenseId]);
            if ($request === null || (string) ($request['status'] ?? '') !== 'submitted') { throw new RuntimeException('Đề xuất chi phí không còn ở trạng thái chờ duyệt.'); }
            $decision = $action === 'approve' ? 'approved' : 'rejected';
            $comment = trim((string) ($_POST['comment'] ?? ''));
            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO expense_approvals (expense_id, approver_id, approved_at, decision, comment) VALUES (:expense_id, :approver_id, NOW(), :decision, :comment)');
            $insert->execute(['expense_id' => $expenseId, 'approver_id' => currentUserId(), 'decision' => $decision, 'comment' => $comment !== '' ? $comment : null]);
            $update = $pdo->prepare('UPDATE expense_requests SET status = :status WHERE id = :id');
            $update->execute(['status' => $decision, 'id' => $expenseId]);
            $pdo->commit();
            setFlash('success', $action === 'approve' ? 'Đã duyệt đề xuất chi phí.' : 'Đã từ chối đề xuất chi phí.');
            redirect('modules/admin/expense_approve.php?status=' . ($action === 'approve' ? 'approved' : 'rejected'));
        }

        if ($action === 'pay') {
            $request = fetchOneSafe($pdo, 'SELECT id, status FROM expense_requests WHERE id = :id LIMIT 1', ['id' => $expenseId]);
            if ($request === null || (string) ($request['status'] ?? '') !== 'approved') { throw new RuntimeException('Đề xuất chi phí chưa sẵn sàng để thanh toán.'); }
            $paymentDate = trim((string) ($_POST['payment_date'] ?? ''));
            $amountPaid = trim((string) ($_POST['amount_paid'] ?? '0'));
            $method = trim((string) ($_POST['method'] ?? ''));
            $note = trim((string) ($_POST['note'] ?? ''));
            if ($paymentDate === '') { throw new RuntimeException('Ngày thanh toán không được để trống.'); }
            if (!is_numeric($amountPaid) || (float) $amountPaid <= 0) { throw new RuntimeException('Số tiền thanh toán phải lớn hơn 0.'); }
            if (!in_array($method, ['tien_mat', 'chuyen_khoan'], true)) { throw new RuntimeException('Phương thức thanh toán không hợp lệ.'); }
            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO expense_payments (expense_id, payment_date, amount_paid, method, paid_by, note, created_at) VALUES (:expense_id, :payment_date, :amount_paid, :method, :paid_by, :note, NOW())');
            $insert->execute(['expense_id' => $expenseId, 'payment_date' => $paymentDate, 'amount_paid' => (float) $amountPaid, 'method' => $method, 'paid_by' => currentUserId(), 'note' => $note !== '' ? $note : null]);
            $update = $pdo->prepare('UPDATE expense_requests SET status = :status WHERE id = :id');
            $update->execute(['status' => 'paid', 'id' => $expenseId]);
            $pdo->commit();
            setFlash('success', 'Đã ghi nhận thanh toán chi phí.');
            redirect('modules/admin/expense_approve.php?status=paid');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        setFlash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'Không thể xử lý yêu cầu chi phí.');
        redirect('modules/admin/expense_approve.php?status=' . $statusTab);
    }
}

$requests = fetchAllSafe($pdo, 'SELECT er.id, er.request_code, er.request_date, er.amount_requested, er.purpose, er.status, er.note, ec.name AS category_name, u.full_name AS requester_name, u.avatar FROM expense_requests er INNER JOIN expense_categories ec ON ec.id = er.category_id INNER JOIN users u ON u.id = er.requester_id WHERE er.status = :status ORDER BY er.request_date DESC, er.id DESC', ['status' => $statusTab]);

$pageTitle = 'Duyệt chi phí';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => 'dashboard.php'],
    ['label' => 'Hành chính', 'url' => 'modules/admin/index.php'],
    ['label' => 'Duyệt chi phí'],
];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4"><div><h1 class="h3 mb-1">Duyệt chi phí</h1><p class="text-muted mb-0">Quản lý phê duyệt và thanh toán các đề xuất chi phí nội bộ.</p></div></div>
<ul class="nav nav-tabs mb-4"><li class="nav-item"><a class="nav-link <?= $statusTab === 'submitted' ? 'active' : '' ?>" href="<?= e(basePath('modules/admin/expense_approve.php?status=submitted')) ?>">Chờ duyệt</a></li><li class="nav-item"><a class="nav-link <?= $statusTab === 'approved' ? 'active' : '' ?>" href="<?= e(basePath('modules/admin/expense_approve.php?status=approved')) ?>">Đã duyệt</a></li><li class="nav-item"><a class="nav-link <?= $statusTab === 'paid' ? 'active' : '' ?>" href="<?= e(basePath('modules/admin/expense_approve.php?status=paid')) ?>">Đã thanh toán</a></li><li class="nav-item"><a class="nav-link <?= $statusTab === 'rejected' ? 'active' : '' ?>" href="<?= e(basePath('modules/admin/expense_approve.php?status=rejected')) ?>">Từ chối</a></li></ul>
<div class="card content-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Người đề xuất</th><th>Danh mục</th><th>Mục đích</th><th>Số tiền</th><th>Ngày đề xuất</th><th class="text-end">Actions</th></tr></thead><tbody><?php if ($requests === []): ?><tr><td colspan="6" class="text-center py-4 text-muted">Không có yêu cầu phù hợp.</td></tr><?php else: ?><?php foreach ($requests as $request): ?><tr><td><div class="d-flex align-items-center gap-2"><?= adminAvatarHtml($request['requester_name'] ?? null, $request['avatar'] ?? null) ?><div><div class="fw-semibold"><?= e($request['requester_name'] ?? '—') ?></div><small class="text-muted"><?= e($request['request_code'] ?? '—') ?></small></div></div></td><td><?= e($request['category_name'] ?? '—') ?></td><td><div><?= e($request['purpose'] ?? '—') ?></div><small class="text-muted"><?= e($request['note'] ?? '') ?></small></td><td class="fw-semibold text-success"><?= e(formatCurrency($request['amount_requested'] ?? 0)) ?></td><td><?= e(formatDate($request['request_date'] ?? null)) ?></td><td class="text-end"><?php if ($statusTab === 'submitted'): ?><div class="btn-group"><button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#approveModal" data-expense-id="<?= e((string) $request['id']) ?>" data-request-code="<?= e((string) ($request['request_code'] ?? '')) ?>">Duyệt</button><button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal" data-expense-id="<?= e((string) $request['id']) ?>" data-request-code="<?= e((string) ($request['request_code'] ?? '')) ?>">Từ chối</button></div><?php elseif ($statusTab === 'approved'): ?><button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#payModal" data-expense-id="<?= e((string) $request['id']) ?>" data-request-code="<?= e((string) ($request['request_code'] ?? '')) ?>" data-amount="<?= e((string) ($request['amount_requested'] ?? 0)) ?>">Thanh toán</button><?php else: ?><span class="badge text-bg-<?= e(adminExpenseStatusBadgeClass((string) ($request['status'] ?? ''))) ?>"><?= e(adminExpenseStatusLabel((string) ($request['status'] ?? ''))) ?></span><?php endif; ?></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div></div>
<div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form method="post" class="modal-content"><?= csrf_input() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="expense_id" id="approveExpenseId"><div class="modal-header"><h2 class="modal-title fs-5">Duyệt đề xuất</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><p class="mb-3">Bạn đang duyệt đề xuất <strong id="approveRequestCode"></strong>.</p><label class="form-label">Nhận xét</label><textarea name="comment" class="form-control" rows="4"></textarea></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy</button><button type="submit" class="btn btn-success">Duyệt</button></div></form></div></div>
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form method="post" class="modal-content"><?= csrf_input() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="expense_id" id="rejectExpenseId"><div class="modal-header"><h2 class="modal-title fs-5">Từ chối đề xuất</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><p class="mb-3">Bạn đang từ chối đề xuất <strong id="rejectRequestCode"></strong>.</p><label class="form-label">Lý do / nhận xét</label><textarea name="comment" class="form-control" rows="4"></textarea></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy</button><button type="submit" class="btn btn-danger">Từ chối</button></div></form></div></div>
<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form method="post" class="modal-content"><?= csrf_input() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="expense_id" id="payExpenseId"><div class="modal-header"><h2 class="modal-title fs-5">Thanh toán đề xuất</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><p class="mb-3">Thanh toán đề xuất <strong id="payRequestCode"></strong>.</p><div class="row g-3"><div class="col-md-6"><label class="form-label">Ngày thanh toán</label><input type="date" name="payment_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" required></div><div class="col-md-6"><label class="form-label">Số tiền thanh toán</label><input type="number" name="amount_paid" id="payAmount" class="form-control" min="0" step="1000" required></div><div class="col-12"><label class="form-label">Phương thức</label><select name="method" class="form-select" required><option value="tien_mat">Tiền mặt</option><option value="chuyen_khoan">Chuyển khoản</option></select></div><div class="col-12"><label class="form-label">Ghi chú</label><textarea name="note" class="form-control" rows="3"></textarea></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy</button><button type="submit" class="btn btn-primary">Xác nhận thanh toán</button></div></form></div></div>
<script>
(() => {
    const bindModal = (modalId, idField, codeField, amountField = null) => {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        modal.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            if (!button) return;
            document.getElementById(idField).value = button.getAttribute('data-expense-id') || '';
            document.getElementById(codeField).textContent = button.getAttribute('data-request-code') || '';
            if (amountField) { document.getElementById(amountField).value = button.getAttribute('data-amount') || ''; }
        });
    };
    bindModal('approveModal', 'approveExpenseId', 'approveRequestCode');
    bindModal('rejectModal', 'rejectExpenseId', 'rejectRequestCode');
    bindModal('payModal', 'payExpenseId', 'payRequestCode', 'payAmount');
})();
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
