<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once __DIR__ . '/helpers.php';

requireRole(['director', 'accountant', 'manager']);

$pageTitle = 'Cài đặt ca làm việc';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => '/ntn_erp/index.php'],
    ['label' => 'Cài đặt ca làm việc'],
];
$shiftReady = tableExists($pdo, 'shifts');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $shiftReady) {
    validateCsrfOrAbort();
    $action = $_POST['action'] ?? '';
    $cols = shiftColumns($pdo);

    if (in_array($action, ['create_shift', 'update_shift'], true)) {
        $shiftId = (int) ($_POST['shift_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $abbreviation = trim((string) ($_POST['abbreviation'] ?? ''));
        $timeStart = (string) ($_POST['time_start'] ?? '');
        $timeEnd = (string) ($_POST['time_end'] ?? '');
        $breakMinutes = (int) ($_POST['break_minutes'] ?? 0);
        $workingHours = (float) ($_POST['working_hours'] ?? 0);
        $color = trim((string) ($_POST['color'] ?? '#0d6efd'));

        if ($workingHours <= 0 && $timeStart !== '' && $timeEnd !== '') {
            $workingHours = max(calculateHourDifference($timeStart, $timeEnd) - ($breakMinutes / 60), 0);
        }

        if ($name === '' || $timeStart === '' || $timeEnd === '') {
            setFlashMessage('danger', 'Vui lòng nhập đầy đủ thông tin ca làm việc.');
            redirect('/ntn_erp/modules/attendance/shift_setup.php');
        }

        if ($action === 'create_shift') {
            $fields = [$cols['name'], $cols['time_start'], $cols['time_end'], $cols['break_minutes'], $cols['working_hours'], $cols['color']];
            $values = [$name, $timeStart, $timeEnd, $breakMinutes, $workingHours, $color];
            if ($cols['abbr']) {
                $fields[] = $cols['abbr'];
                $values[] = $abbreviation;
            }
            $stmt = $pdo->prepare('INSERT INTO shifts (`' . implode('`, `', $fields) . '`) VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')');
            $stmt->execute($values);
            setFlashMessage('success', 'Đã thêm ca làm việc mới.');
        } else {
            $setParts = [
                '`' . $cols['name'] . '` = ?',
                '`' . $cols['time_start'] . '` = ?',
                '`' . $cols['time_end'] . '` = ?',
                '`' . $cols['break_minutes'] . '` = ?',
                '`' . $cols['working_hours'] . '` = ?',
                '`' . $cols['color'] . '` = ?',
            ];
            $values = [$name, $timeStart, $timeEnd, $breakMinutes, $workingHours, $color];
            if ($cols['abbr']) {
                $setParts[] = '`' . $cols['abbr'] . '` = ?';
                $values[] = $abbreviation;
            }
            $values[] = $shiftId;
            $stmt = $pdo->prepare('UPDATE shifts SET ' . implode(', ', $setParts) . ' WHERE `' . $cols['id'] . '` = ?');
            $stmt->execute($values);
            setFlashMessage('success', 'Đã cập nhật ca làm việc.');
        }
    }

    if ($action === 'delete_shift') {
        $shiftId = (int) ($_POST['shift_id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM shifts WHERE `' . $cols['id'] . '` = ?');
        $stmt->execute([$shiftId]);
        setFlashMessage('success', 'Đã xóa ca làm việc.');
    }

    redirect('/ntn_erp/modules/attendance/shift_setup.php');
}

$shifts = fetchShiftList($pdo);
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Cài đặt ca làm việc</h1>
        <p class="text-muted mb-0">Quản lý danh sách ca, màu hiển thị và số giờ làm việc chuẩn.</p>
    </div>
    <button class="btn btn-primary no-print" data-bs-toggle="modal" data-bs-target="#shiftModal" data-mode="create">
        <i class="fa-solid fa-plus me-2"></i>Thêm ca
    </button>
</div>
<?php if (!$shiftReady): ?>
    <div class="alert alert-warning">Chưa tìm thấy bảng <code>shifts</code>. Vui lòng tạo schema trước khi sử dụng.</div>
<?php endif; ?>
<div class="card content-card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table align-middle table-hover">
                <thead class="table-light">
                <tr>
                    <th>Tên ca</th>
                    <th>Mã</th>
                    <th>Giờ bắt đầu</th>
                    <th>Giờ kết thúc</th>
                    <th>Nghỉ (phút)</th>
                    <th>Số giờ công</th>
                    <th>Màu</th>
                    <th class="no-print">Thao tác</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$shifts): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Chưa có ca làm việc.</td></tr>
                <?php endif; ?>
                <?php foreach ($shifts as $shift): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($shift['name']) ?></td>
                        <td><?= e($shift['abbreviation'] ?: '-') ?></td>
                        <td><?= e(substr($shift['time_start'], 0, 5)) ?></td>
                        <td><?= e(substr($shift['time_end'], 0, 5)) ?></td>
                        <td><?= e($shift['break_minutes']) ?></td>
                        <td><?= e(number_format((float) $shift['working_hours'], 2)) ?></td>
                        <td><span class="d-inline-block rounded-circle border" style="width:24px;height:24px;background:<?= e($shift['color']) ?>"></span></td>
                        <td class="no-print">
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#shiftModal"
                                    data-mode="edit" data-id="<?= e($shift['id']) ?>" data-name="<?= e($shift['name']) ?>" data-abbreviation="<?= e($shift['abbreviation']) ?>"
                                    data-time-start="<?= e($shift['time_start']) ?>" data-time-end="<?= e($shift['time_end']) ?>" data-break-minutes="<?= e($shift['break_minutes']) ?>"
                                    data-working-hours="<?= e($shift['working_hours']) ?>" data-color="<?= e($shift['color']) ?>">Sửa</button>
                                <form method="post" onsubmit="return confirm('Xóa ca này?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_shift">
                                    <input type="hidden" name="shift_id" value="<?= e($shift['id']) ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Xóa</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="modal fade" id="shiftModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="shiftForm">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="shiftModalTitle">Thêm ca làm việc</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" id="shiftAction" value="create_shift">
                    <input type="hidden" name="shift_id" id="shiftId">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Tên ca</label>
                            <input type="text" name="name" id="shiftName" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Mã ca</label>
                            <input type="text" name="abbreviation" id="shiftAbbreviation" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Màu</label>
                            <input type="color" name="color" id="shiftColor" class="form-control form-control-color" value="#0d6efd">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Giờ bắt đầu</label>
                            <input type="time" name="time_start" id="shiftTimeStart" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Giờ kết thúc</label>
                            <input type="time" name="time_end" id="shiftTimeEnd" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Nghỉ giữa ca (phút)</label>
                            <input type="number" min="0" name="break_minutes" id="shiftBreakMinutes" class="form-control" value="60">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Số giờ công</label>
                            <input type="number" min="0" step="0.25" name="working_hours" id="shiftWorkingHours" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Đóng</button>
                    <button type="submit" class="btn btn-primary">Lưu ca</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    const shiftModal = document.getElementById('shiftModal');
    const calcShiftHours = () => {
        const start = document.getElementById('shiftTimeStart').value;
        const end = document.getElementById('shiftTimeEnd').value;
        const breakMinutes = Number(document.getElementById('shiftBreakMinutes').value || 0);
        if (!start || !end) return;
        const [sh, sm] = start.split(':').map(Number);
        const [eh, em] = end.split(':').map(Number);
        const diff = Math.max((eh * 60 + em) - (sh * 60 + sm) - breakMinutes, 0);
        document.getElementById('shiftWorkingHours').value = (diff / 60).toFixed(2);
    };
    ['shiftTimeStart','shiftTimeEnd','shiftBreakMinutes'].forEach(id => document.getElementById(id)?.addEventListener('change', calcShiftHours));
    shiftModal?.addEventListener('show.bs.modal', event => {
        const button = event.relatedTarget;
        const isEdit = button?.getAttribute('data-mode') === 'edit';
        document.getElementById('shiftModalTitle').textContent = isEdit ? 'Cập nhật ca làm việc' : 'Thêm ca làm việc';
        document.getElementById('shiftAction').value = isEdit ? 'update_shift' : 'create_shift';
        document.getElementById('shiftId').value = button?.getAttribute('data-id') || '';
        document.getElementById('shiftName').value = button?.getAttribute('data-name') || '';
        document.getElementById('shiftAbbreviation').value = button?.getAttribute('data-abbreviation') || '';
        document.getElementById('shiftTimeStart').value = (button?.getAttribute('data-time-start') || '').slice(0,5);
        document.getElementById('shiftTimeEnd').value = (button?.getAttribute('data-time-end') || '').slice(0,5);
        document.getElementById('shiftBreakMinutes').value = button?.getAttribute('data-break-minutes') || 60;
        document.getElementById('shiftWorkingHours').value = button?.getAttribute('data-working-hours') || '';
        document.getElementById('shiftColor').value = button?.getAttribute('data-color') || '#0d6efd';
    });
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
