<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once __DIR__ . '/helpers.php';

requireRole(['director', 'manager']);

$pageTitle = 'Duyệt nghỉ phép';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => '/ntn_erp/index.php'],
    ['label' => 'Duyệt nghỉ phép'],
];
$tab = $_GET['tab'] ?? 'pending';
$leaveTableReady = tableExists($pdo, 'leave_requests');
$userTable = getEmployeeSourceTable($pdo);
$userNameColumn = $userTable ? (pickColumn($pdo, $userTable, ['full_name', 'name', 'employee_name', 'username']) ?? 'id') : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $leaveTableReady) {
    validateCsrfOrAbort();
    $action = $_POST['action'] ?? '';
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $comment = trim((string) ($_POST['comment'] ?? ''));
    if ($requestId > 0 && in_array($action, ['approve', 'reject'], true)) {
        $cols = leaveColumns($pdo);
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = $pdo->prepare(sprintf('UPDATE leave_requests SET `%s` = ?, `%s` = ?, `%s` = NOW(), `%s` = ? WHERE `%s` = ?', $cols['status'], $cols['approved_by'], $cols['approved_at'], $cols['comment'], $cols['id']));
        $stmt->execute([$status, currentUserId(), $comment, $requestId]);
        setFlashMessage('success', $status === 'approved' ? 'Đã duyệt đơn nghỉ phép.' : 'Đã từ chối đơn nghỉ phép.');
    }
    redirect('/ntn_erp/modules/attendance/leave_manage.php?tab=' . urlencode($tab));
}

$requestsByTab = ['pending' => [], 'approved' => [], 'rejected' => []];
if ($leaveTableReady) {
    $cols = leaveColumns($pdo);
    $typeExpression = tableExists($pdo, 'leave_types') ? 'lt.name' : (columnExists($pdo, 'leave_requests', $cols['type']) ? 'lr.`' . $cols['type'] . '`' : 'NULL');
    $leaveTypeJoin = tableExists($pdo, 'leave_types') ? 'LEFT JOIN leave_types lt ON lt.id = lr.`' . $cols['type_id'] . '` ' : '';
    foreach (array_keys($requestsByTab) as $status) {
        $sql = sprintf(
            'SELECT lr.`%s` AS id, %s AS leave_type, lr.`%s` AS date_from, lr.`%s` AS date_to, lr.`%s` AS days, lr.`%s` AS reason, lr.`%s` AS status, lr.`%s` AS comment, lr.`%s` AS approved_at, %s AS employee_name
             FROM leave_requests lr %s%s
             WHERE lr.`%s` = ?
             ORDER BY lr.`%s` ASC, lr.`%s` DESC',
            $cols['id'], $typeExpression, $cols['from'], $cols['to'], $cols['days'], $cols['reason'], $cols['status'], $cols['comment'], $cols['approved_at'],
            $userTable && $userNameColumn ? 'u.`' . $userNameColumn . '`' : 'NULL',
            $leaveTypeJoin,
            $userTable && $userNameColumn ? 'LEFT JOIN `' . $userTable . '` u ON u.id = lr.`' . $cols['user'] . '`' : '',
            $cols['status'], $cols['from'], $cols['id']
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$status]);
        $requestsByTab[$status] = $stmt->fetchAll();
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">Duyệt nghỉ phép</h1>
        <p class="text-muted mb-0">Xử lý nhanh các đơn nghỉ phép của nhân viên theo trạng thái.</p>
    </div>
</div>

<ul class="nav nav-tabs mb-4 no-print">
    <?php foreach (['pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Đã từ chối'] as $key => $label): ?>
        <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="?tab=<?= e($key) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
</ul>

<div class="card content-card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table align-middle table-hover">
                <thead class="table-light">
                <tr>
                    <th>Nhân viên</th>
                    <th>Loại nghỉ</th>
                    <th>Từ ngày</th>
                    <th>Đến ngày</th>
                    <th>Số ngày</th>
                    <th>Lý do</th>
                    <th>Ghi chú duyệt</th>
                    <?php if ($tab === 'pending'): ?><th class="no-print">Thao tác</th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php if (!$requestsByTab[$tab]): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Không có yêu cầu ở trạng thái này.</td></tr>
                <?php endif; ?>
                <?php foreach ($requestsByTab[$tab] as $request): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($request['employee_name'] ?: 'N/A') ?></td>
                        <td><?= e($request['leave_type']) ?></td>
                        <td><?= e(formatDateVN($request['date_from'])) ?></td>
                        <td><?= e(formatDateVN($request['date_to'])) ?></td>
                        <td><?= e($request['days']) ?></td>
                        <td><?= e($request['reason']) ?></td>
                        <td><?= e($request['comment'] ?: '-') ?></td>
                        <?php if ($tab === 'pending'): ?>
                            <td class="no-print">
                                <div class="d-flex gap-2">
                                    <form method="post">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="request_id" value="<?= e($request['id']) ?>">
                                        <button type="submit" class="btn btn-sm btn-success">Duyệt</button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectLeaveModal" data-request-id="<?= e($request['id']) ?>">Từ chối</button>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="rejectLeaveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h2 class="modal-title fs-5">Từ chối đơn nghỉ phép</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="request_id" id="rejectLeaveRequestId">
                    <label class="form-label">Lý do từ chối / ghi chú</label>
                    <textarea name="comment" class="form-control" rows="4" placeholder="Nhập phản hồi cho nhân viên"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Đóng</button>
                    <button type="submit" class="btn btn-danger">Xác nhận từ chối</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    document.getElementById('rejectLeaveModal')?.addEventListener('show.bs.modal', event => {
        document.getElementById('rejectLeaveRequestId').value = event.relatedTarget.getAttribute('data-request-id');
    });
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
