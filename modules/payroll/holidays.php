<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireRole('director', 'accountant');

$pdo = getDBConnection();
$user = currentUser();
$selectedYear = max(2020, min(2100, (int) ($_GET['year'] ?? date('Y'))));
$editId = (int) ($_GET['edit'] ?? 0);
$errors = [];
$editHoliday = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc không hợp lệ.');
        header('Location: /ntn_erp/modules/payroll/holidays.php?year=' . $selectedYear);
        exit();
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM holidays WHERE id = ?");
        $stmt->execute([(int) ($_POST['holiday_id'] ?? 0)]);
        setFlash('success', 'Đã xóa ngày lễ.');
        header('Location: /ntn_erp/modules/payroll/holidays.php?year=' . $selectedYear);
        exit();
    }

    $holidayDate = $_POST['holiday_date'] ?? '';
    $holidayName = trim($_POST['holiday_name'] ?? '');
    $year = $holidayDate ? (int) date('Y', strtotime($holidayDate)) : $selectedYear;
    if (!$holidayDate || $holidayName === '') $errors[] = 'Vui lòng nhập đầy đủ ngày và tên ngày lễ.';

    if (!$errors) {
        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO holidays (holiday_date, holiday_name, year, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$holidayDate, $holidayName, $year, (int) $user['id']]);
            setFlash('success', 'Đã thêm ngày lễ mới.');
        }
        if ($action === 'update') {
            $stmt = $pdo->prepare("UPDATE holidays SET holiday_date = ?, holiday_name = ?, year = ? WHERE id = ?");
            $stmt->execute([$holidayDate, $holidayName, $year, (int) ($_POST['holiday_id'] ?? 0)]);
            setFlash('success', 'Đã cập nhật ngày lễ.');
        }
        header('Location: /ntn_erp/modules/payroll/holidays.php?year=' . $year);
        exit();
    }
}

if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM holidays WHERE id = ? LIMIT 1");
    $stmt->execute([$editId]);
    $editHoliday = $stmt->fetch() ?: null;
}

$holidaysStmt = $pdo->prepare("SELECT * FROM holidays WHERE year = ? ORDER BY holiday_date ASC");
$holidaysStmt->execute([$selectedYear]);
$holidays = $holidaysStmt->fetchAll();
$formData = $editHoliday ?: ['holiday_date' => '', 'holiday_name' => ''];

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="main-content">
    <div class="container-fluid py-4">
        <?php showFlash(); ?>
        <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary text-white"><?= $editHoliday ? 'Cập nhật ngày lễ' : 'Thêm ngày lễ' ?></div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="<?= $editHoliday ? 'update' : 'create' ?>">
                            <input type="hidden" name="holiday_id" value="<?= (int) ($formData['id'] ?? 0) ?>">
                            <div class="col-12"><label class="form-label">Ngày lễ</label><input class="form-control" type="date" name="holiday_date" value="<?= e($formData['holiday_date']) ?>" required></div>
                            <div class="col-12"><label class="form-label">Tên ngày lễ</label><input class="form-control" name="holiday_name" value="<?= e($formData['holiday_name']) ?>" required></div>
                            <div class="col-12 d-grid gap-2"><button class="btn btn-primary" type="submit">Lưu ngày lễ</button><?php if ($editHoliday): ?><a class="btn btn-outline-secondary" href="/ntn_erp/modules/payroll/holidays.php?year=<?= $selectedYear ?>">Hủy chỉnh sửa</a><?php endif; ?></div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 mb-3">
                    <div class="card-body">
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-md-4"><label class="form-label">Năm</label><input class="form-control" type="number" name="year" value="<?= $selectedYear ?>"></div>
                            <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit">Xem</button></div>
                        </form>
                    </div>
                </div>
                <div class="card shadow-sm border-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>Ngày</th><th>Tên ngày lễ</th><th>Năm</th><th class="text-end">Thao tác</th></tr></thead>
                            <tbody>
                                <?php if (!$holidays): ?><tr><td colspan="4" class="text-center text-muted py-4">Chưa có ngày lễ nào cho năm này.</td></tr><?php endif; ?>
                                <?php foreach ($holidays as $holiday): ?>
                                    <tr>
                                        <td><?= e(formatDate($holiday['holiday_date'])) ?></td>
                                        <td><?= e($holiday['holiday_name']) ?></td>
                                        <td><?= e($holiday['year']) ?></td>
                                        <td class="text-end"><div class="btn-group btn-group-sm"><a class="btn btn-outline-primary" href="?year=<?= $selectedYear ?>&edit=<?= (int) $holiday['id'] ?>">Sửa</a><form method="post" onsubmit="return confirm('Xóa ngày lễ này?');"><?= csrf_input() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="holiday_id" value="<?= (int) $holiday['id'] ?>"><button class="btn btn-outline-danger" type="submit">Xóa</button></form></div></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
