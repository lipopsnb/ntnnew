<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/modules/attendance/helpers.php';

requireRole(['director', 'accountant', 'manager']);

$pageTitle = 'Quản lý ngày lễ';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => '/ntn_erp/index.php'],
    ['label' => 'Quản lý ngày lễ'],
];
$holidayReady = tableExists($pdo, 'holidays');
$currentYear = (int) date('Y');

function defaultVietnamHolidaySeeds(int $year): array
{
    $variableByYear = [
        2026 => [
            ['name' => 'Tết Nguyên Đán - Mùng 1', 'date' => '2026-02-17', 'recurring' => 0],
            ['name' => 'Tết Nguyên Đán - Mùng 2', 'date' => '2026-02-18', 'recurring' => 0],
            ['name' => 'Tết Nguyên Đán - Mùng 3', 'date' => '2026-02-19', 'recurring' => 0],
            ['name' => 'Giỗ Tổ Hùng Vương', 'date' => '2026-04-24', 'recurring' => 0],
        ],
    ];

    return array_merge([
        ['name' => 'Tết Dương lịch', 'date' => $year . '-01-01', 'recurring' => 1],
        ['name' => 'Ngày Giải phóng miền Nam', 'date' => $year . '-04-30', 'recurring' => 1],
        ['name' => 'Quốc tế Lao động', 'date' => $year . '-05-01', 'recurring' => 1],
        ['name' => 'Quốc khánh', 'date' => $year . '-09-02', 'recurring' => 1],
    ], $variableByYear[$year] ?? []);
}

if ($holidayReady) {
    $cols = holidayColumns($pdo);
    $count = (int) $pdo->query('SELECT COUNT(*) FROM holidays')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare(sprintf('INSERT INTO holidays (`%s`, `%s`, `%s`) VALUES (?, ?, ?)', $cols['name'], $cols['date'], $cols['recurring']));
        foreach (defaultVietnamHolidaySeeds($currentYear) as $holiday) {
            $stmt->execute([$holiday['name'], $holiday['date'], $holiday['recurring']]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $holidayReady) {
    validateCsrfOrAbort();
    $cols = holidayColumns($pdo);
    $action = $_POST['action'] ?? '';

    if ($action === 'save_holiday') {
        $holidayId = (int) ($_POST['holiday_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $date = (string) ($_POST['holiday_date'] ?? '');
        $isRecurring = isset($_POST['is_recurring']) ? 1 : 0;
        if ($holidayId > 0) {
            $stmt = $pdo->prepare(sprintf('UPDATE holidays SET `%s` = ?, `%s` = ?, `%s` = ? WHERE `%s` = ?', $cols['name'], $cols['date'], $cols['recurring'], $cols['id']));
            $stmt->execute([$name, $date, $isRecurring, $holidayId]);
            setFlashMessage('success', 'Đã cập nhật ngày lễ.');
        } else {
            $stmt = $pdo->prepare(sprintf('INSERT INTO holidays (`%s`, `%s`, `%s`) VALUES (?, ?, ?)', $cols['name'], $cols['date'], $cols['recurring']));
            $stmt->execute([$name, $date, $isRecurring]);
            setFlashMessage('success', 'Đã thêm ngày lễ.');
        }
    }

    if ($action === 'delete_holiday') {
        $holidayId = (int) ($_POST['holiday_id'] ?? 0);
        $stmt = $pdo->prepare(sprintf('DELETE FROM holidays WHERE `%s` = ?', $cols['id']));
        $stmt->execute([$holidayId]);
        setFlashMessage('success', 'Đã xóa ngày lễ.');
    }

    if ($action === 'seed_holidays') {
        $stmt = $pdo->prepare(sprintf('INSERT INTO holidays (`%s`, `%s`, `%s`) VALUES (?, ?, ?)', $cols['name'], $cols['date'], $cols['recurring']));
        foreach (defaultVietnamHolidaySeeds((int) ($_POST['seed_year'] ?? $currentYear)) as $holiday) {
            $check = $pdo->prepare(sprintf('SELECT COUNT(*) FROM holidays WHERE `%s` = ? AND `%s` = ?', $cols['name'], $cols['date']));
            $check->execute([$holiday['name'], $holiday['date']]);
            if (!(int) $check->fetchColumn()) {
                $stmt->execute([$holiday['name'], $holiday['date'], $holiday['recurring']]);
            }
        }
        setFlashMessage('success', 'Đã nạp ngày lễ mẫu Việt Nam.');
    }

    redirect('/ntn_erp/modules/payroll/holidays.php');
}

$holidays = [];
if ($holidayReady) {
    $cols = holidayColumns($pdo);
    $stmt = $pdo->query(sprintf('SELECT `%s` AS id, `%s` AS name, `%s` AS holiday_date, `%s` AS is_recurring FROM holidays ORDER BY `%s`', $cols['id'], $cols['name'], $cols['date'], $cols['recurring'], $cols['date']));
    $holidays = $stmt->fetchAll();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Quản lý ngày lễ</h1>
        <p class="text-muted mb-0">Quản lý danh sách ngày nghỉ lễ dùng cho chấm công và tính lương.</p>
    </div>
    <div class="d-flex gap-2 no-print">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="seed_holidays">
            <input type="hidden" name="seed_year" value="<?= $currentYear ?>">
            <button type="submit" class="btn btn-outline-primary">Nạp ngày lễ Việt Nam</button>
        </form>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#holidayModal" data-mode="create">Thêm ngày lễ</button>
    </div>
</div>
<?php if (!$holidayReady): ?>
    <div class="alert alert-warning">Chưa tìm thấy bảng <code>holidays</code>. Vui lòng tạo schema trước khi sử dụng.</div>
<?php endif; ?>
<div class="card content-card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table align-middle table-hover">
                <thead class="table-light">
                <tr>
                    <th>Tên ngày lễ</th>
                    <th>Ngày</th>
                    <th>Lặp lại hằng năm</th>
                    <th class="no-print">Thao tác</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$holidays): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">Chưa có ngày lễ.</td></tr>
                <?php endif; ?>
                <?php foreach ($holidays as $holiday): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($holiday['name']) ?></td>
                        <td><?= e(formatDateVN($holiday['holiday_date'])) ?></td>
                        <td><?= (int) $holiday['is_recurring'] === 1 ? '<span class="badge bg-success">Có</span>' : '<span class="badge bg-secondary">Không</span>' ?></td>
                        <td class="no-print">
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#holidayModal"
                                    data-mode="edit" data-id="<?= e($holiday['id']) ?>" data-name="<?= e($holiday['name']) ?>" data-date="<?= e($holiday['holiday_date']) ?>" data-recurring="<?= e($holiday['is_recurring']) ?>">Sửa</button>
                                <form method="post" onsubmit="return confirm('Xóa ngày lễ này?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_holiday">
                                    <input type="hidden" name="holiday_id" value="<?= e($holiday['id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Xóa</button>
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
<div class="modal fade" id="holidayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="holidayModalTitle">Thêm ngày lễ</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_holiday">
                    <input type="hidden" name="holiday_id" id="holidayId">
                    <div class="mb-3">
                        <label class="form-label">Tên ngày lễ</label>
                        <input type="text" name="name" id="holidayName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ngày</label>
                        <input type="date" name="holiday_date" id="holidayDate" class="form-control" required>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_recurring" id="holidayRecurring">
                        <label class="form-check-label" for="holidayRecurring">Lặp lại hằng năm</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Đóng</button>
                    <button type="submit" class="btn btn-primary">Lưu ngày lễ</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    document.getElementById('holidayModal')?.addEventListener('show.bs.modal', event => {
        const button = event.relatedTarget;
        const isEdit = button?.getAttribute('data-mode') === 'edit';
        document.getElementById('holidayModalTitle').textContent = isEdit ? 'Cập nhật ngày lễ' : 'Thêm ngày lễ';
        document.getElementById('holidayId').value = button?.getAttribute('data-id') || '';
        document.getElementById('holidayName').value = button?.getAttribute('data-name') || '';
        document.getElementById('holidayDate').value = button?.getAttribute('data-date') || '';
        document.getElementById('holidayRecurring').checked = button?.getAttribute('data-recurring') === '1';
    });
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
