<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once __DIR__ . '/helpers.php';

requireLogin();

$pageTitle = 'Đơn xin nghỉ phép';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => '/ntn_erp/index.php'],
    ['label' => 'Đơn xin nghỉ phép'],
];
$userId = currentUserId();
$currentYear = (int) date('Y');
$leaveTableReady = tableExists($pdo, 'leave_requests');
$leaveTypes = tableExists($pdo, 'leave_types')
    ? fetchAllSafe($pdo, 'SELECT id, name FROM leave_types ORDER BY name ASC')
    : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_leave') {
    validateCsrfOrAbort();
    if (!$leaveTableReady) {
        setFlashMessage('danger', 'Thiếu bảng leave_requests trong cơ sở dữ liệu.');
        redirect('/ntn_erp/modules/attendance/leave_request.php');
    }

    $leaveTypeId = (int) ($_POST['leave_type_id'] ?? 0);
    $dateFrom = (string) ($_POST['date_from'] ?? '');
    $dateTo = (string) ($_POST['date_to'] ?? '');
    $reason = trim((string) ($_POST['reason'] ?? ''));

    if ($leaveTypeId <= 0 || $dateFrom === '' || $dateTo === '' || $reason === '') {
        setFlashMessage('danger', 'Vui lòng nhập đầy đủ thông tin đơn nghỉ phép.');
        redirect('/ntn_erp/modules/attendance/leave_request.php');
    }

    $days = calculateLeaveDays($dateFrom, $dateTo);
    $cols = leaveColumns($pdo);
    $stmt = $pdo->prepare(sprintf('INSERT INTO leave_requests (`%s`, `%s`, `%s`, `%s`, `%s`, `%s`, `%s`) VALUES (?, ?, ?, ?, ?, ?, ?)', $cols['user'], $cols['type_id'], $cols['from'], $cols['to'], $cols['days'], $cols['reason'], $cols['status']));
    $stmt->execute([$userId, $leaveTypeId, $dateFrom, $dateTo, $days, $reason, 'pending']);

    setFlashMessage('success', 'Đã tạo đơn xin nghỉ phép, chờ duyệt.');
    redirect('/ntn_erp/modules/attendance/leave_request.php');
}

$remainingLeave = getLeaveBalance($pdo, (int) $userId);
$requests = [];
if ($leaveTableReady) {
    $cols = leaveColumns($pdo);
    $typeExpression = tableExists($pdo, 'leave_types') ? 'lt.name' : (columnExists($pdo, 'leave_requests', $cols['type']) ? 'lr.`' . $cols['type'] . '`' : 'NULL');
    $joinLeaveType = tableExists($pdo, 'leave_types') ? 'LEFT JOIN leave_types lt ON lt.id = lr.`' . $cols['type_id'] . '`' : '';
    $stmt = $pdo->prepare(sprintf('SELECT lr.`%s` AS id, %s AS leave_type, lr.`%s` AS date_from, lr.`%s` AS date_to, lr.`%s` AS days, lr.`%s` AS reason, lr.`%s` AS status, lr.`%s` AS comment FROM leave_requests lr %s WHERE lr.`%s` = ? ORDER BY lr.`%s` DESC, lr.`%s` DESC', $cols['id'], $typeExpression, $cols['from'], $cols['to'], $cols['days'], $cols['reason'], $cols['status'], $cols['comment'], $joinLeaveType, $cols['user'], $cols['from'], $cols['id']));
    $stmt->execute([$userId]);
    $requests = $stmt->fetchAll();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Đơn xin nghỉ phép</h1>
        <p class="text-muted mb-0">Quản lý các đơn nghỉ phép cá nhân và theo dõi số ngày phép còn lại.</p>
    </div>
    <button class="btn btn-primary no-print" data-bs-toggle="modal" data-bs-target="#leaveModal">
        <i class="fa-solid fa-plus me-2"></i>Tạo đơn nghỉ phép
    </button>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-4">
        <div class="card content-card h-100">
            <div class="card-body">
                <div class="text-muted small mb-2">Số ngày phép còn lại năm <?= $currentYear ?></div>
                <div class="display-6 fw-bold text-primary"><?= e(number_format($remainingLeave, 1)) ?> ngày</div>
                <div class="small text-muted mt-2">Mặc định hệ thống dùng 12 ngày/năm nếu chưa có bảng cân đối phép.</div>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 mb-3">Tình trạng đơn gần đây</h2>
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge bg-warning text-dark">Chờ duyệt</span>
                    <span class="badge bg-success">Đã duyệt</span>
                    <span class="badge bg-danger">Đã từ chối</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card content-card">
    <div class="card-body">
        <h2 class="h5 mb-3">Danh sách đơn nghỉ phép của tôi</h2>
        <div class="table-responsive">
            <table class="table align-middle table-hover">
                <thead class="table-light">
                <tr>
                    <th>Loại nghỉ</th>
                    <th>Từ ngày</th>
                    <th>Đến ngày</th>
                    <th>Số ngày</th>
                    <th>Lý do</th>
                    <th>Trạng thái</th>
                    <th>Phản hồi</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$requests): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Chưa có đơn nghỉ phép.</td></tr>
                <?php endif; ?>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td><?= e($request['leave_type']) ?></td>
                        <td><?= e(formatDateVN($request['date_from'])) ?></td>
                        <td><?= e(formatDateVN($request['date_to'])) ?></td>
                        <td><?= e($request['days']) ?></td>
                        <td><?= e($request['reason']) ?></td>
                        <td><span class="badge bg-<?= e(leaveStatusBadge($request['status'])) ?>"><?= e(ucfirst($request['status'])) ?></span></td>
                        <td><?= e($request['comment'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="leaveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="leaveRequestForm">
                <div class="modal-header">
                    <h2 class="modal-title fs-5">Tạo đơn nghỉ phép</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create_leave">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Loại nghỉ</label>
                            <select name="leave_type_id" class="form-select" required>
                                <option value="">Chọn loại nghỉ</option>
                                <?php foreach ($leaveTypes as $leaveType): ?>
                                    <option value="<?= e($leaveType['id']) ?>"><?= e($leaveType['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Từ ngày</label>
                            <input type="date" name="date_from" id="leaveDateFrom" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Đến ngày</label>
                            <input type="date" name="date_to" id="leaveDateTo" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Số ngày</label>
                            <input type="number" id="leaveDays" class="form-control" value="1" readonly>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Lý do</label>
                            <textarea name="reason" class="form-control" rows="3" required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Đóng</button>
                    <button type="submit" class="btn btn-primary">Gửi đơn</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    const leaveDateFrom = document.getElementById('leaveDateFrom');
    const leaveDateTo = document.getElementById('leaveDateTo');
    const leaveDays = document.getElementById('leaveDays');
    const updateLeaveDays = () => {
        if (!leaveDateFrom.value || !leaveDateTo.value) return;
        const from = new Date(leaveDateFrom.value);
        const to = new Date(leaveDateTo.value);
        const diff = Math.floor((to - from) / 86400000) + 1;
        leaveDays.value = diff > 0 ? diff : 1;
    };
    leaveDateFrom?.addEventListener('change', updateLeaveDays);
    leaveDateTo?.addEventListener('change', updateLeaveDays);
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
