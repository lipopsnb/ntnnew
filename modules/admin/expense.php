<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once __DIR__ . '/helpers.php';

requireLogin();

$currentUser = currentUser();
$currentUserId = (int) ($currentUser['id'] ?? 0);
$currentRole = (string) ($currentUser['role'] ?? 'employee');
$isApprover = in_array($currentRole, ['director', 'accountant'], true);
$tab = (string) ($_GET['tab'] ?? 'mine');
if (!in_array($tab, ['mine', 'pending', 'history'], true)) { $tab = 'mine'; }
if (!$isApprover && $tab === 'pending') { $tab = 'mine'; }
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensurePostCsrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $purpose = trim((string) ($_POST['purpose'] ?? ''));
        $amountRequested = trim((string) ($_POST['amount_requested'] ?? '0'));
        $note = trim((string) ($_POST['note'] ?? ''));

        $categoryExists = (int) fetchScalarSafe($pdo, 'SELECT COUNT(*) FROM expense_categories WHERE id = :id', ['id' => $categoryId], 0);
        if ($categoryExists === 0) { $errors[] = 'Danh mục chi phí không hợp lệ.'; }
        if ($purpose === '') { $errors[] = 'Mục đích chi phí không được để trống.'; }
        if (!is_numeric($amountRequested) || (float) $amountRequested <= 0) { $errors[] = 'Số tiền đề xuất phải lớn hơn 0.'; }

        if ($errors === []) {
            $insert = $pdo->prepare('INSERT INTO expense_requests (request_code, category_id, requester_id, request_date, amount_requested, purpose, status, note, created_at) VALUES (:request_code, :category_id, :requester_id, CURDATE(), :amount_requested, :purpose, :status, :note, NOW())');
            $insert->execute(['request_code' => adminGenerateExpenseRequestCode($pdo), 'category_id' => $categoryId, 'requester_id' => $currentUserId, 'amount_requested' => (float) $amountRequested, 'purpose' => $purpose, 'status' => 'draft', 'note' => $note !== '' ? $note : null]);
            setFlash('success', 'Đã tạo đề xuất chi phí ở trạng thái nháp.');
            redirect('modules/admin/expense.php?tab=mine');
        }
    }

    if ($action === 'submit') {
        $expenseId = (int) ($_POST['expense_id'] ?? 0);
        $sql = 'UPDATE expense_requests SET status = :status WHERE id = :id AND status = :draft';
        $params = ['status' => 'submitted', 'id' => $expenseId, 'draft' => 'draft'];
        if (!$isApprover) { $sql .= ' AND requester_id = :requester_id'; $params['requester_id'] = $currentUserId; }
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        setFlash($statement->rowCount() > 0 ? 'success' : 'danger', $statement->rowCount() > 0 ? 'Đã nộp đề xuất chi phí thành công.' : 'Không thể nộp đề xuất đã chọn.');
        redirect('modules/admin/expense.php?tab=mine');
    }
}

$categories = fetchAllSafe($pdo, 'SELECT id, name FROM expense_categories ORDER BY name ASC');
$mineRequests = fetchAllSafe($pdo, 'SELECT er.*, ec.name AS category_name, u.full_name AS requester_name FROM expense_requests er INNER JOIN expense_categories ec ON ec.id = er.category_id INNER JOIN users u ON u.id = er.requester_id WHERE er.requester_id = :requester_id ORDER BY er.request_date DESC, er.id DESC', ['requester_id' => $currentUserId]);
$pendingRequests = $isApprover ? fetchAllSafe($pdo, "SELECT er.*, ec.name AS category_name, u.full_name AS requester_name FROM expense_requests er INNER JOIN expense_categories ec ON ec.id = er.category_id INNER JOIN users u ON u.id = er.requester_id WHERE er.status = 'submitted' ORDER BY er.request_date DESC, er.id DESC") : [];
$historySql = 'SELECT er.*, ec.name AS category_name, u.full_name AS requester_name FROM expense_requests er INNER JOIN expense_categories ec ON ec.id = er.category_id INNER JOIN users u ON u.id = er.requester_id WHERE er.status != :draft';
$historyParams = ['draft' => 'draft'];
if (!$isApprover) { $historySql .= ' AND er.requester_id = :requester_id'; $historyParams['requester_id'] = $currentUserId; }
$historySql .= ' ORDER BY er.request_date DESC, er.id DESC';
$historyRequests = fetchAllSafe($pdo, $historySql, $historyParams);

$renderRows = static function (array $requests, bool $allowSubmit) use ($isApprover): void {
    if ($requests === []) {
        echo '<tr><td colspan="8" class="text-center py-4 text-muted">Không có dữ liệu phù hợp.</td></tr>';
        return;
    }

    foreach ($requests as $request) {
        echo '<tr>';
        echo '<td class="fw-semibold">' . e($request['request_code'] ?? '—') . '</td>';
        echo '<td>' . e($request['category_name'] ?? '—') . '</td>';
        echo '<td><div>' . e($request['purpose'] ?? '—') . '</div>';
        if ($isApprover) { echo '<small class="text-muted">Người đề xuất: ' . e($request['requester_name'] ?? '—') . '</small>'; }
        echo '</td>';
        echo '<td class="fw-semibold text-success">' . e(formatCurrency($request['amount_requested'] ?? 0)) . '</td>';
        echo '<td><span class="badge text-bg-' . e(adminExpenseStatusBadgeClass((string) ($request['status'] ?? ''))) . '">' . e(adminExpenseStatusLabel((string) ($request['status'] ?? ''))) . '</span></td>';
        echo '<td>' . e(formatDate($request['request_date'] ?? null)) . '</td>';
        echo '<td>' . adminExpenseTimeline((string) ($request['status'] ?? '')) . '</td>';
        echo '<td class="text-end">';
        if ($allowSubmit && (string) ($request['status'] ?? '') === 'draft') {
            echo '<form method="post" class="d-inline">' . csrf_input();
            echo '<input type="hidden" name="action" value="submit">';
            echo '<input type="hidden" name="expense_id" value="' . e((string) ($request['id'] ?? 0)) . '">';
            echo '<button type="submit" class="btn btn-sm btn-outline-primary">Nộp đề xuất</button>';
            echo '</form>';
        } else {
            echo '<span class="text-muted small">—</span>';
        }
        echo '</td></tr>';
    }
};

$pageTitle = 'Đề xuất chi phí';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => 'dashboard.php'],
    ['label' => 'Đề xuất chi phí'],
];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<div class="card content-card mb-4"><div class="card-body p-4"><div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3"><div><h1 class="h4 mb-1">Đề xuất chi phí</h1><p class="text-muted mb-0">Tạo, theo dõi và nộp các đề xuất chi phí nội bộ.</p></div><button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#expenseCreateForm"><i class="fa-solid fa-plus me-2"></i>Tạo đề xuất</button></div><?php if ($errors !== []): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?><div class="collapse show" id="expenseCreateForm"><form method="post" class="row g-3"><?= csrf_input() ?><input type="hidden" name="action" value="create"><div class="col-md-4"><label class="form-label">Danh mục</label><select name="category_id" class="form-select" required><option value="">Chọn danh mục</option><?php foreach ($categories as $category): ?><option value="<?= e((string) $category['id']) ?>" <?= (string) old('category_id') === (string) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">Số tiền đề xuất</label><input type="number" name="amount_requested" class="form-control" min="0" step="1000" value="<?= e((string) old('amount_requested')) ?>" required></div><div class="col-md-4"><label class="form-label">Ghi chú</label><input type="text" name="note" class="form-control" value="<?= e((string) old('note')) ?>"></div><div class="col-12"><label class="form-label">Mục đích</label><textarea name="purpose" class="form-control" rows="3" required><?= e((string) old('purpose')) ?></textarea></div><div class="col-12 d-flex justify-content-end"><button type="submit" class="btn btn-success">Lưu nháp</button></div></form></div></div></div>
<ul class="nav nav-tabs mb-4"><li class="nav-item"><a class="nav-link <?= $tab === 'mine' ? 'active' : '' ?>" href="<?= e(basePath('modules/admin/expense.php?tab=mine')) ?>">Của tôi</a></li><?php if ($isApprover): ?><li class="nav-item"><a class="nav-link <?= $tab === 'pending' ? 'active' : '' ?>" href="<?= e(basePath('modules/admin/expense.php?tab=pending')) ?>">Chờ duyệt</a></li><?php endif; ?><li class="nav-item"><a class="nav-link <?= $tab === 'history' ? 'active' : '' ?>" href="<?= e(basePath('modules/admin/expense.php?tab=history')) ?>">Lịch sử</a></li></ul>
<div class="card content-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Mã đề xuất</th><th>Danh mục</th><th>Mục đích</th><th>Số tiền</th><th>Trạng thái</th><th>Ngày đề xuất</th><th>Tiến độ</th><th class="text-end">Thao tác</th></tr></thead><tbody><?php if ($tab === 'mine') { $renderRows($mineRequests, true); } elseif ($tab === 'pending') { $renderRows($pendingRequests, false); } else { $renderRows($historyRequests, false); } ?></tbody></table></div></div></div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
