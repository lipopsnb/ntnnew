<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once __DIR__ . '/helpers.php';

requireRole(['director', 'manager']);

$pageTitle = 'Duyệt tăng ca';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => '/ntn_erp/index.php'],
    ['label' => 'Duyệt tăng ca'],
];
$tab = $_GET['tab'] ?? 'pending';
$otReady = tableExists($pdo, 'overtime_requests');
$userTable = getEmployeeSourceTable($pdo);
$userNameColumn = $userTable ? (pickColumn($pdo, $userTable, ['full_name', 'name', 'employee_name', 'username']) ?? 'id') : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $otReady) {
    validateCsrfOrAbort();
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $comment = trim((string) ($_POST['comment'] ?? ''));
    if ($requestId > 0 && in_array($action, ['approve', 'reject'], true)) {
        $cols = otColumns($pdo);
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = $pdo->prepare(sprintf('UPDATE overtime_requests SET `%s` = ?, `%s` = ?, `%s` = NOW(), `%s` = ? WHERE `%s` = ?', $cols['status'], $cols['approved_by'], $cols['approved_at'], $cols['comment'], $cols['id']));
        $stmt->execute([$status, currentUserId(), $comment, $requestId]);
        setFlashMessage('success', $status === 'approved' ? 'Đã duyệt yêu cầu tăng ca.' : 'Đã từ chối yêu cầu tăng ca.');
    }
    redirect('/ntn_erp/modules/attendance/ot_manage.php?tab=' . urlencode($tab));
}

$requestsByTab = ['pending' => [], 'approved' => [], 'rejected' => []];
if ($otReady) {
    $cols = otColumns($pdo);
    foreach (array_keys($requestsByTab) as $status) {
        $sql = sprintf(
            'SELECT ot.`%s` AS id, ot.`%s` AS ot_date, ot.`%s` AS time_start, ot.`%s` AS time_end, ot.`%s` AS hours, ot.`%s` AS reason, ot.`%s` AS status, ot.`%s` AS comment, %s AS employee_name
             FROM overtime_requests ot %s
             WHERE ot.`%s` = ?
             ORDER BY ot.`%s` ASC, ot.`%s` DESC',
            $cols['id'], $cols['date'], $cols['time_start'], $cols['time_end'], $cols['hours'], $cols['reason'], $cols['status'], $cols['comment'],
            $userTable && $userNameColumn ? 'u.`' . $userNameColumn . '`' : 'NULL',
            $userTable && $userNameColumn ? 'LEFT JOIN `' . $userTable . '` u ON u.id = ot.`' . $cols['user'] . '`' : '',
            $cols['status'], $cols['date'], $cols['id']
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$status]);
        $requestsByTab[$status] = $stmt->fetchAll();
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="mb-4">
    <h1 class="h3 mb-1">Duyệt tăng ca</h1>
    <p class="text-muted mb-0">Duyệt hoặc từ chối đề nghị tăng ca từ nhân viên.</p>
</div>
<ul class="nav nav-tabs mb-4 no-print">
    <?php foreach (['pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Từ chối'] as $key => $label): ?>
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
                    <th>Ngày</th>
                    <th>Khung giờ</th>
                    <th>Số giờ</th>
                    <th>Lý do</th>
                    <th>Phản hồi</th>
                    <?php if ($tab === 'pending'): ?><th class="no-print">Thao tác</th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php if (!$requestsByTab[$tab]): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Không có yêu cầu ở trạng thái này.</td></tr>
                <?php endif; ?>
                <?php foreach ($requestsByTab[$tab] as $request): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($request['employee_name'] ?: 'N/A') ?></td>
                        <td><?= e(formatDateVN($request['ot_date'])) ?></td>
                        <td><?= e(substr($request['time_start'], 0, 5)) ?> - <?= e(substr($request['time_end'], 0, 5)) ?></td>
                        <td><?= e(number_format((float) $request['hours'], 2)) ?></td>
                        <td><?= e($request['reason']) ?></td>
                        <td><?= e($request['comment'] ?: '-') ?></td>
                        <?php if ($tab === 'pending'): ?>
                            <td class="no-print">
                                <div class="d-flex gap-2">
                                    <form method="post">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="request_id" value="<?= e($request['id']) ?>">
                                        <button class="btn btn-sm btn-success" type="submit">Duyệt</button>
                                    </form>
                                    <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#rejectOtModal" data-request-id="<?= e($request['id']) ?>">Từ chối</button>
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
<div class="modal fade" id="rejectOtModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h2 class="modal-title fs-5">Từ chối tăng ca</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="request_id" id="rejectOtRequestId">
                    <label class="form-label">Ghi chú</label>
                    <textarea name="comment" class="form-control" rows="4"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Đóng</button>
                    <button type="submit" class="btn btn-danger">Xác nhận</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    document.getElementById('rejectOtModal')?.addEventListener('show.bs.modal', event => {
        document.getElementById('rejectOtRequestId').value = event.relatedTarget.getAttribute('data-request-id');
    });
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
