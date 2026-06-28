<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once __DIR__ . '/helpers.php';

requireLogin();

$pageTitle = 'Đăng ký tăng ca';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => '/ntn_erp/index.php'],
    ['label' => 'Đăng ký tăng ca'],
];
$userId = currentUserId();
$otReady = tableExists($pdo, 'overtime_requests');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_ot') {
    validateCsrfOrAbort();
    if (!$otReady) {
        setFlashMessage('danger', 'Thiếu bảng overtime_requests trong cơ sở dữ liệu.');
        redirect('/ntn_erp/modules/attendance/ot_request.php');
    }

    $otDate = (string) ($_POST['ot_date'] ?? '');
    $timeStart = (string) ($_POST['time_start'] ?? '');
    $timeEnd = (string) ($_POST['time_end'] ?? '');
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $hours = calculateHourDifference($timeStart, $timeEnd);

    if ($otDate === '' || $timeStart === '' || $timeEnd === '' || $reason === '' || $hours <= 0) {
        setFlashMessage('danger', 'Vui lòng nhập đủ thông tin tăng ca hợp lệ.');
        redirect('/ntn_erp/modules/attendance/ot_request.php');
    }

    $cols = otColumns($pdo);
    $stmt = $pdo->prepare(sprintf('INSERT INTO overtime_requests (`%s`, `%s`, `%s`, `%s`, `%s`, `%s`, `%s`) VALUES (?, ?, ?, ?, ?, ?, ?)', $cols['user'], $cols['date'], $cols['time_start'], $cols['time_end'], $cols['hours'], $cols['reason'], $cols['status']));
    $stmt->execute([$userId, $otDate, $timeStart, $timeEnd, $hours, $reason, 'pending']);
    setFlashMessage('success', 'Đã gửi đăng ký tăng ca.');
    redirect('/ntn_erp/modules/attendance/ot_request.php');
}

$requests = [];
if ($otReady) {
    $cols = otColumns($pdo);
    $stmt = $pdo->prepare(sprintf('SELECT `%s` AS id, `%s` AS ot_date, `%s` AS time_start, `%s` AS time_end, `%s` AS hours, `%s` AS reason, `%s` AS status, `%s` AS comment FROM overtime_requests WHERE `%s` = ? ORDER BY `%s` DESC, `%s` DESC', $cols['id'], $cols['date'], $cols['time_start'], $cols['time_end'], $cols['hours'], $cols['reason'], $cols['status'], $cols['comment'], $cols['user'], $cols['date'], $cols['id']));
    $stmt->execute([$userId]);
    $requests = $stmt->fetchAll();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card content-card h-100">
            <div class="card-body">
                <h1 class="h4 mb-3">Đăng ký tăng ca</h1>
                <form method="post" id="otForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create_ot">
                    <div class="mb-3">
                        <label class="form-label">Ngày tăng ca</label>
                        <input type="date" name="ot_date" class="form-control" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Từ giờ</label>
                            <input type="time" name="time_start" id="otTimeStart" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Đến giờ</label>
                            <input type="time" name="time_end" id="otTimeEnd" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Số giờ</label>
                        <input type="number" step="0.25" name="hours_preview" id="otHoursPreview" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Lý do</label>
                        <textarea name="reason" rows="4" class="form-control" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Gửi yêu cầu tăng ca</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 mb-3">Danh sách đăng ký tăng ca của tôi</h2>
                <div class="table-responsive">
                    <table class="table align-middle table-hover">
                        <thead class="table-light">
                        <tr>
                            <th>Ngày</th>
                            <th>Khung giờ</th>
                            <th>Số giờ</th>
                            <th>Lý do</th>
                            <th>Trạng thái</th>
                            <th>Phản hồi</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$requests): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">Chưa có đăng ký tăng ca.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($requests as $request): ?>
                            <tr>
                                <td><?= e(formatDateVN($request['ot_date'])) ?></td>
                                <td><?= e(substr($request['time_start'], 0, 5)) ?> - <?= e(substr($request['time_end'], 0, 5)) ?></td>
                                <td><?= e(number_format((float) $request['hours'], 2)) ?></td>
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
    </div>
</div>
<script>
    const otTimeStart = document.getElementById('otTimeStart');
    const otTimeEnd = document.getElementById('otTimeEnd');
    const otHoursPreview = document.getElementById('otHoursPreview');
    const updateOtHours = () => {
        if (!otTimeStart.value || !otTimeEnd.value) return;
        const [sh, sm] = otTimeStart.value.split(':').map(Number);
        const [eh, em] = otTimeEnd.value.split(':').map(Number);
        const start = sh * 60 + sm;
        const end = eh * 60 + em;
        otHoursPreview.value = Math.max((end - start) / 60, 0).toFixed(2);
    };
    otTimeStart?.addEventListener('change', updateOtHours);
    otTimeEnd?.addEventListener('change', updateOtHours);
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
