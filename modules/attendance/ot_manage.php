<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireRole('director', 'manager', 'production');

$pdo = getDBConnection();
$user = currentUser();
$otTypeLabels = ['weekday' => 'Ngày thường', 'weekend' => 'Cuối tuần', 'holiday' => 'Ngày lễ'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc không hợp lệ.');
        header('Location: /ntn_erp/modules/attendance/ot_manage.php');
        exit();
    }

    $requestId = (int) ($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $rejectReason = trim($_POST['reject_reason'] ?? '');

    $reqStmt = $pdo->prepare("SELECT * FROM overtime_requests WHERE id = ? LIMIT 1");
    $reqStmt->execute([$requestId]);
    $request = $reqStmt->fetch();
    if (!$request || $request['status'] !== 'pending') {
        setFlash('danger', 'Yêu cầu OT không còn ở trạng thái chờ duyệt.');
        header('Location: /ntn_erp/modules/attendance/ot_manage.php');
        exit();
    }

    if ($action === 'approve') {
        $stmt = $pdo->prepare("UPDATE overtime_requests SET status = 'approved', actual_hours = hours, approved_by = ?, approved_at = NOW(), reject_reason = NULL WHERE id = ? AND status = 'pending'");
        $stmt->execute([(int) $user['id'], $requestId]);
        $message = 'Yêu cầu OT ngày ' . formatDate($request['ot_date']) . ' đã được duyệt.';
        setFlash('success', 'Đã duyệt yêu cầu OT.');
    } else {
        if ($rejectReason === '') {
            setFlash('danger', 'Vui lòng nhập lý do từ chối.');
            header('Location: /ntn_erp/modules/attendance/ot_manage.php');
            exit();
        }
        $stmt = $pdo->prepare("UPDATE overtime_requests SET status = 'rejected', reject_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending'");
        $stmt->execute([$rejectReason, (int) $user['id'], $requestId]);
        $message = 'Yêu cầu OT ngày ' . formatDate($request['ot_date']) . ' bị từ chối. Lý do: ' . $rejectReason;
        setFlash('success', 'Đã từ chối yêu cầu OT.');
    }

    $notifyStmt = $pdo->prepare(
        "INSERT INTO notifications (user_id, title, message, type, reference_id, is_read, created_at)
         VALUES (?, ?, ?, ?, ?, 0, NOW())"
    );
    $notifyStmt->execute([(int) $request['user_id'], 'Kết quả yêu cầu OT', $message, 'ot_request', $requestId]);

    header('Location: /ntn_erp/modules/attendance/ot_manage.php');
    exit();
}

$status = $_GET['status'] ?? 'pending';
$fromDate = $_GET['from_date'] ?? '';
$toDate = $_GET['to_date'] ?? '';
$sql = "SELECT ot.*, u.full_name, u.employee_code, d.name AS department_name, ws.shift_name
        FROM overtime_requests ot
        INNER JOIN users u ON u.id = ot.user_id
        LEFT JOIN departments d ON d.id = ot.department_id
        LEFT JOIN work_shifts ws ON ws.id = ot.shift_id
        WHERE 1=1";
$params = [];
if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
    $sql .= ' AND ot.status = ?';
    $params[] = $status;
}
if ($fromDate !== '') {
    $sql .= ' AND ot.ot_date >= ?';
    $params[] = $fromDate;
}
if ($toDate !== '') {
    $sql .= ' AND ot.ot_date <= ?';
    $params[] = $toDate;
}
$sql .= " ORDER BY CASE ot.status WHEN 'pending' THEN 0 ELSE 1 END, ot.ot_date DESC, ot.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="main-content">
    <div class="container-fluid py-4">
        <?php showFlash(); ?>
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-md-3"><label class="form-label">Trạng thái</label><select class="form-select" name="status"><option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Chờ duyệt</option><option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Đã duyệt</option><option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Từ chối</option><option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Tất cả</option></select></div>
                    <div class="col-md-3"><label class="form-label">Từ ngày</label><input type="date" class="form-control" name="from_date" value="<?= e($fromDate) ?>"></div>
                    <div class="col-md-3"><label class="form-label">Đến ngày</label><input type="date" class="form-control" name="to_date" value="<?= e($toDate) ?>"></div>
                    <div class="col-md-3 d-grid"><button class="btn btn-primary" type="submit">Lọc</button></div>
                </form>
            </div>
        </div>
        <div class="card shadow-sm border-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Nhân viên</th><th>Ngày</th><th>Giờ OT</th><th>Loại</th><th>Lý do</th><th>Trạng thái</th><th class="text-end">Thao tác</th></tr></thead>
                    <tbody>
                        <?php if (!$requests): ?><tr><td colspan="7" class="text-center text-muted py-4">Không có yêu cầu OT.</td></tr><?php endif; ?>
                        <?php foreach ($requests as $request): ?>
                            <?php $badge = $request['status'] === 'approved' ? 'success' : ($request['status'] === 'rejected' ? 'danger' : 'warning text-dark'); ?>
                            <tr>
                                <td><div class="fw-semibold"><?= e($request['full_name']) ?></div><div class="small text-muted"><?= e($request['employee_code']) ?> • <?= e($request['department_name'] ?? 'Chưa có phòng ban') ?></div></td>
                                <td><?= e(formatDate($request['ot_date'])) ?></td>
                                <td><?= e($request['hours']) ?> giờ</td>
                                <td><?= e($otTypeLabels[$request['ot_type']] ?? $request['ot_type']) ?></td>
                                <td><?= e($request['reason']) ?></td>
                                <td><span class="badge bg-<?= $badge ?>"><?= e($request['status']) ?></span><?php if (!empty($request['reject_reason'])): ?><div class="small text-muted mt-1"><?= e($request['reject_reason']) ?></div><?php endif; ?></td>
                                <td class="text-end">
                                    <?php if ($request['status'] === 'pending'): ?>
                                        <div class="d-inline-flex gap-1">
                                            <form method="post"><?= csrf_input() ?><input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>"><input type="hidden" name="action" value="approve"><button class="btn btn-success btn-sm" type="submit">Duyệt</button></form>
                                            <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#rejectOtModal" data-id="<?= (int) $request['id'] ?>">Từ chối</button>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small">Đã xử lý</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="rejectOtModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content"><form method="post"><div class="modal-header"><h5 class="modal-title">Từ chối yêu cầu OT</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><?= csrf_input() ?><input type="hidden" name="request_id" id="rejectOtRequestId"><input type="hidden" name="action" value="reject"><label class="form-label">Lý do từ chối</label><textarea class="form-control" name="reject_reason" rows="4" required></textarea></div><div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Đóng</button><button class="btn btn-danger" type="submit">Xác nhận</button></div></form></div></div>
</div>
<script>
document.getElementById('rejectOtModal').addEventListener('show.bs.modal', function (event) {
    document.getElementById('rejectOtRequestId').value = event.relatedTarget.getAttribute('data-id');
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
