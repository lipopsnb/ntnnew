<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireRole('director', 'accountant');

$pdo = getDBConnection();
$user = currentUser();
$errors = [];

function payrollHolidaySet(PDO $pdo, string $startDate, string $endDate): array {
    $stmt = $pdo->prepare("SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?");
    $stmt->execute([$startDate, $endDate]);
    return array_flip(array_column($stmt->fetchAll(), 'holiday_date'));
}

function payrollWorkingDays(PDO $pdo, string $startDate, string $endDate): int {
    $holidaySet = payrollHolidaySet($pdo, $startDate, $endDate);
    $cursor = new DateTime($startDate);
    $limit = new DateTime($endDate);
    $count = 0;
    while ($cursor <= $limit) {
        $date = $cursor->format('Y-m-d');
        if ((int) $cursor->format('N') !== 7 && !isset($holidaySet[$date])) {
            $count++;
        }
        $cursor->modify('+1 day');
    }
    return $count;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc không hợp lệ.');
        header('Location: /ntn_erp/modules/payroll/index.php');
        exit();
    }

    $action = $_POST['action'] ?? '';
    $periodId = (int) ($_POST['period_id'] ?? 0);

    if ($action === 'create') {
        $year = (int) ($_POST['period_year'] ?? 0);
        $month = (int) ($_POST['period_month'] ?? 0);
        $periodFrom = $_POST['period_from'] ?? '';
        $periodTo = $_POST['period_to'] ?? '';
        $note = trim($_POST['note'] ?? '');
        $workingDays = $_POST['working_days'] !== '' ? (int) $_POST['working_days'] : 0;

        if ($year <= 0 || $month <= 0 || !$periodFrom || !$periodTo) $errors[] = 'Vui lòng nhập đầy đủ thông tin kỳ lương.';
        if ($periodTo < $periodFrom) $errors[] = 'Ngày kết thúc phải lớn hơn hoặc bằng ngày bắt đầu.';
        if ($workingDays <= 0 && !$errors) $workingDays = payrollWorkingDays($pdo, $periodFrom, $periodTo);

        if (!$errors) {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM payroll_periods WHERE period_year = ? AND period_month = ?");
            $checkStmt->execute([$year, $month]);
            if ((int) $checkStmt->fetchColumn() > 0) {
                $errors[] = 'Kỳ lương này đã tồn tại.';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO payroll_periods (period_year, period_month, period_from, period_to, working_days, status, note, created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, NOW(), NOW())"
                );
                $stmt->execute([$year, $month, $periodFrom, $periodTo, $workingDays, $note ?: null, (int) $user['id']]);
                setFlash('success', 'Đã tạo kỳ lương mới.');
                header('Location: /ntn_erp/modules/payroll/index.php');
                exit();
            }
        }
    }

    if ($action === 'submit') {
        $stmt = $pdo->prepare("UPDATE payroll_periods SET status = 'submitted', submitted_at = NOW(), submitted_by = ?, updated_at = NOW() WHERE id = ? AND status = 'draft'");
        $stmt->execute([(int) $user['id'], $periodId]);
        setFlash('success', 'Đã gửi kỳ lương lên duyệt.');
        header('Location: /ntn_erp/modules/payroll/index.php');
        exit();
    }

    if ($action === 'approve') {
        $stmt = $pdo->prepare("UPDATE payroll_periods SET status = 'approved', approved_at = NOW(), approved_by = ?, updated_at = NOW() WHERE id = ? AND status IN ('draft', 'submitted')");
        $stmt->execute([(int) $user['id'], $periodId]);
        setFlash('success', 'Đã duyệt kỳ lương.');
        header('Location: /ntn_erp/modules/payroll/index.php');
        exit();
    }

    if ($action === 'lock') {
        $stmt = $pdo->prepare("UPDATE payroll_periods SET status = 'locked', locked_at = NOW(), locked_by = ?, updated_at = NOW() WHERE id = ? AND status = 'approved'");
        $stmt->execute([(int) $user['id'], $periodId]);
        setFlash('success', 'Đã khóa kỳ lương.');
        header('Location: /ntn_erp/modules/payroll/index.php');
        exit();
    }
}

$periods = $pdo->query(
    "SELECT pp.*,
            (SELECT COUNT(*) FROM payroll_slips ps WHERE ps.period_id = pp.id) AS slip_count
     FROM payroll_periods pp
     ORDER BY pp.period_year DESC, pp.period_month DESC"
)->fetchAll();
$statusMeta = ['draft' => ['Nháp', 'secondary'], 'submitted' => ['Đã gửi', 'warning text-dark'], 'approved' => ['Đã duyệt', 'success'], 'locked' => ['Đã khóa', 'dark']];

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
                    <div class="card-header bg-primary text-white">Tạo kỳ lương</div>
                    <div class="card-body">
                        <form method="post" class="row g-3" id="periodForm">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="create">
                            <div class="col-md-6"><label class="form-label">Năm</label><input class="form-control" type="number" name="period_year" value="<?= e($_POST['period_year'] ?? date('Y')) ?>" required></div>
                            <div class="col-md-6"><label class="form-label">Tháng</label><input class="form-control" type="number" min="1" max="12" name="period_month" value="<?= e($_POST['period_month'] ?? date('n')) ?>" required></div>
                            <div class="col-md-6"><label class="form-label">Từ ngày</label><input class="form-control" type="date" name="period_from" value="<?= e($_POST['period_from'] ?? date('Y-m-01')) ?>" required></div>
                            <div class="col-md-6"><label class="form-label">Đến ngày</label><input class="form-control" type="date" name="period_to" value="<?= e($_POST['period_to'] ?? date('Y-m-t')) ?>" required></div>
                            <div class="col-12"><label class="form-label">Ngày công</label><input class="form-control" type="number" name="working_days" value="<?= e($_POST['working_days'] ?? '') ?>" placeholder="Để trống để tự tính"></div>
                            <div class="col-12"><label class="form-label">Ghi chú</label><textarea class="form-control" name="note" rows="3"><?= e($_POST['note'] ?? '') ?></textarea></div>
                            <div class="col-12 d-grid"><button class="btn btn-primary" type="submit">Tạo kỳ lương</button></div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white fw-semibold">Danh sách kỳ lương</div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>Kỳ lương</th><th>Khoảng ngày</th><th class="text-center">Ngày công</th><th class="text-center">Phiếu lương</th><th>Trạng thái</th><th class="text-end">Thao tác</th></tr></thead>
                            <tbody>
                                <?php if (!$periods): ?><tr><td colspan="6" class="text-center text-muted py-4">Chưa có kỳ lương nào.</td></tr><?php endif; ?>
                                <?php foreach ($periods as $period): ?>
                                    <?php [$label, $class] = $statusMeta[$period['status']] ?? ['Không rõ', 'secondary']; ?>
                                    <tr>
                                        <td><?= e($period['period_month'] . '/' . $period['period_year']) ?></td>
                                        <td><?= e(formatDate($period['period_from']) . ' - ' . formatDate($period['period_to'])) ?></td>
                                        <td class="text-center"><?= e($period['working_days']) ?></td>
                                        <td class="text-center"><?= e($period['slip_count']) ?></td>
                                        <td><span class="badge bg-<?= e($class) ?>"><?= e($label) ?></span></td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm">
                                                <?php if ($period['status'] === 'draft'): ?><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="submit"><input type="hidden" name="period_id" value="<?= (int) $period['id'] ?>"><button class="btn btn-outline-warning" type="submit">Submit</button></form><?php endif; ?>
                                                <?php if (in_array($period['status'], ['draft', 'submitted'], true)): ?><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="period_id" value="<?= (int) $period['id'] ?>"><button class="btn btn-outline-success" type="submit">Approve</button></form><?php endif; ?>
                                                <?php if ($period['status'] === 'approved'): ?><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="lock"><input type="hidden" name="period_id" value="<?= (int) $period['id'] ?>"><button class="btn btn-outline-dark" type="submit">Lock</button></form><?php endif; ?>
                                            </div>
                                        </td>
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
