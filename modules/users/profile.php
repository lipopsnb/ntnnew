<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireLogin();

$pdo = getDBConnection();
$currentUser = currentUser();
$targetId = (int) ($_GET['id'] ?? $currentUser['id']);
if ($targetId !== (int) $currentUser['id'] && !hasRole('director', 'accountant', 'manager')) {
    setFlash('danger', 'Bạn chỉ được xem hồ sơ của chính mình.');
    header('Location: /ntn_erp/modules/users/profile.php?id=' . (int) $currentUser['id']);
    exit();
}

$userStmt = $pdo->prepare(
    "SELECT u.*, r.name AS role_name, r.display_name, d.name AS department_name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE u.id = ? LIMIT 1"
);
$userStmt->execute([$targetId]);
$targetUser = $userStmt->fetch();
if (!$targetUser) {
    setFlash('danger', 'Không tìm thấy hồ sơ nhân viên.');
    header('Location: /ntn_erp/dashboard.php');
    exit();
}

$profileStmt = $pdo->prepare("SELECT * FROM employee_profiles WHERE user_id = ? LIMIT 1");
$profileStmt->execute([$targetId]);
$profile = $profileStmt->fetch() ?: [];
$canEditBasic = hasRole('director', 'accountant');
$canEditProfile = $canEditBasic || $targetId === (int) $currentUser['id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc không hợp lệ.');
        header('Location: /ntn_erp/modules/users/profile.php?id=' . $targetId);
        exit();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_basic' && $canEditBasic) {
        $employeeCode = trim($_POST['employee_code'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $departmentId = $_POST['department_id'] !== '' ? (int) $_POST['department_id'] : null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($employeeCode === '' || $fullName === '' || $username === '' || $roleId <= 0) $errors[] = 'Vui lòng nhập đầy đủ thông tin cơ bản.';
        $uniqueStmt = $pdo->prepare("SELECT SUM(username = ?) AS username_exists, SUM(employee_code = ?) AS code_exists FROM users WHERE id <> ?");
        $uniqueStmt->execute([$username, $employeeCode, $targetId]);
        $unique = $uniqueStmt->fetch();
        if ((int) ($unique['username_exists'] ?? 0) > 0) $errors[] = 'Tên đăng nhập đã tồn tại.';
        if ((int) ($unique['code_exists'] ?? 0) > 0) $errors[] = 'Mã nhân viên đã tồn tại.';

        if (!$errors) {
            $stmt = $pdo->prepare("UPDATE users SET employee_code = ?, full_name = ?, username = ?, email = ?, phone = ?, role_id = ?, department_id = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$employeeCode, $fullName, $username, $email ?: null, $phone ?: null, $roleId, $departmentId, $isActive, $targetId]);
            setFlash('success', 'Đã cập nhật thông tin cơ bản.');
            header('Location: /ntn_erp/modules/users/profile.php?id=' . $targetId);
            exit();
        }
    }

    if ($action === 'save_profile' && $canEditProfile) {
        $data = [
            'gender' => trim($_POST['gender'] ?? ''),
            'date_of_birth' => $_POST['date_of_birth'] ?: null,
            'ethnicity' => trim($_POST['ethnicity'] ?? ''),
            'marital_status' => trim($_POST['marital_status'] ?? ''),
            'mobile_phone' => trim($_POST['mobile_phone'] ?? ''),
            'identity_no' => trim($_POST['identity_no'] ?? ''),
            'identity_issue_date' => $_POST['identity_issue_date'] ?: null,
            'identity_issue_place' => trim($_POST['identity_issue_place'] ?? ''),
            'bank_account' => trim($_POST['bank_account'] ?? ''),
            'bank_name' => trim($_POST['bank_name'] ?? ''),
            'bank_branch' => trim($_POST['bank_branch'] ?? ''),
            'personal_tax_code' => trim($_POST['personal_tax_code'] ?? ''),
            'social_book_no' => trim($_POST['social_book_no'] ?? ''),
            'has_social_insurance' => isset($_POST['has_social_insurance']) ? 1 : 0,
            'insurance_from' => $_POST['insurance_from'] ?: null,
            'date_joined' => $_POST['date_joined'] ?: null,
            'annual_leave_total' => $_POST['annual_leave_total'] !== '' ? (float) $_POST['annual_leave_total'] : null,
            'permanent_province' => $_POST['permanent_province'] ?: null,
            'permanent_district_text' => trim($_POST['permanent_district_text'] ?? ''),
            'permanent_commune_text' => trim($_POST['permanent_commune_text'] ?? ''),
            'dependants' => $_POST['dependants'] !== '' ? (int) $_POST['dependants'] : 0,
        ];
        $columns = array_keys($data);
        $insertSql = "INSERT INTO employee_profiles (user_id, " . implode(', ', $columns) . ", created_at, updated_at) VALUES (?, " . implode(', ', array_fill(0, count($columns), '?')) . ", NOW(), NOW()) ON DUPLICATE KEY UPDATE ";
        $updates = [];
        foreach ($columns as $column) {
            $updates[] = "$column = VALUES($column)";
        }
        $insertSql .= implode(', ', $updates) . ', updated_at = NOW()';
        $stmt = $pdo->prepare($insertSql);
        $stmt->execute(array_merge([$targetId], array_values($data)));
        setFlash('success', 'Đã cập nhật hồ sơ mở rộng.');
        header('Location: /ntn_erp/modules/users/profile.php?id=' . $targetId);
        exit();
    }
}

$roles = $pdo->query("SELECT id, display_name FROM roles ORDER BY id ASC")->fetchAll();
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name ASC")->fetchAll();
$provinces = $pdo->query("SELECT code, name FROM provinces ORDER BY name ASC")->fetchAll();
$ethnicities = $pdo->query("SELECT name FROM ethnicities ORDER BY id ASC")->fetchAll();
$badge = getRoleBadge($targetUser['role_name']);

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="main-content">
    <div class="container-fluid py-4">
        <?php showFlash(); ?>
        <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <div class="d-flex align-items-center gap-3 mb-4">
            <div>
                <h4 class="mb-1">Hồ sơ nhân viên</h4>
                <div class="d-flex flex-wrap gap-2 align-items-center"><strong><?= e($targetUser['full_name']) ?></strong><span class="badge bg-<?= e($badge['class']) ?>"><?= e($badge['label']) ?></span><span class="text-muted"><?= e($targetUser['employee_code']) ?></span></div>
            </div>
        </div>
        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white fw-semibold">1. Thông tin cơ bản</div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="save_basic">
                            <div class="col-md-6"><label class="form-label">Mã nhân viên</label><input class="form-control" name="employee_code" value="<?= e($targetUser['employee_code']) ?>" <?= $canEditBasic ? '' : 'readonly' ?>></div>
                            <div class="col-md-6"><label class="form-label">Tên đăng nhập</label><input class="form-control" name="username" value="<?= e($targetUser['username']) ?>" <?= $canEditBasic ? '' : 'readonly' ?>></div>
                            <div class="col-12"><label class="form-label">Họ tên</label><input class="form-control" name="full_name" value="<?= e($targetUser['full_name']) ?>" <?= $canEditBasic ? '' : 'readonly' ?>></div>
                            <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" value="<?= e($targetUser['email'] ?? '') ?>" <?= $canEditBasic ? '' : 'readonly' ?>></div>
                            <div class="col-md-6"><label class="form-label">Điện thoại</label><input class="form-control" name="phone" value="<?= e($targetUser['phone'] ?? '') ?>" <?= $canEditBasic ? '' : 'readonly' ?>></div>
                            <div class="col-md-6"><label class="form-label">Vai trò</label><select class="form-select" name="role_id" <?= $canEditBasic ? '' : 'disabled' ?>><?php foreach ($roles as $role): ?><option value="<?= (int) $role['id'] ?>" <?= (int) $targetUser['role_id'] === (int) $role['id'] ? 'selected' : '' ?>><?= e($role['display_name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6"><label class="form-label">Phòng ban</label><select class="form-select" name="department_id" <?= $canEditBasic ? '' : 'disabled' ?>><option value="">Chọn phòng ban</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= (string) ($targetUser['department_id'] ?? '') === (string) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" <?= (int) $targetUser['is_active'] === 1 ? 'checked' : '' ?> <?= $canEditBasic ? '' : 'disabled' ?>><label class="form-check-label">Đang hoạt động</label></div></div>
                            <?php if ($canEditBasic): ?><div class="col-12 d-grid"><button class="btn btn-primary" type="submit">Lưu thông tin cơ bản</button></div><?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white fw-semibold">2. Hồ sơ mở rộng</div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="save_profile">
                            <div class="col-md-4"><label class="form-label">Giới tính</label><select class="form-select" name="gender" <?= $canEditProfile ? '' : 'disabled' ?>><option value="">Chọn</option><option value="male" <?= ($profile['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Nam</option><option value="female" <?= ($profile['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Nữ</option><option value="other" <?= ($profile['gender'] ?? '') === 'other' ? 'selected' : '' ?>>Khác</option></select></div>
                            <div class="col-md-4"><label class="form-label">Ngày sinh</label><input class="form-control" type="date" name="date_of_birth" value="<?= e($profile['date_of_birth'] ?? '') ?>" <?= $canEditProfile ? '' : 'readonly' ?>></div>
                            <div class="col-md-4"><label class="form-label">Tình trạng hôn nhân</label><select class="form-select" name="marital_status" <?= $canEditProfile ? '' : 'disabled' ?>><option value="">Chọn</option><option value="single" <?= ($profile['marital_status'] ?? '') === 'single' ? 'selected' : '' ?>>Độc thân</option><option value="married" <?= ($profile['marital_status'] ?? '') === 'married' ? 'selected' : '' ?>>Đã kết hôn</option></select></div>
                            <div class="col-md-6"><label class="form-label">Dân tộc</label><select class="form-select" name="ethnicity" <?= $canEditProfile ? '' : 'disabled' ?>><option value="">Chọn dân tộc</option><?php foreach ($ethnicities as $ethnicity): ?><option value="<?= e($ethnicity['name']) ?>" <?= ($profile['ethnicity'] ?? '') === $ethnicity['name'] ? 'selected' : '' ?>><?= e($ethnicity['name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6"><label class="form-label">Di động</label><input class="form-control" name="mobile_phone" value="<?= e($profile['mobile_phone'] ?? '') ?>" <?= $canEditProfile ? '' : 'readonly' ?>></div>
                            <div class="col-md-6"><label class="form-label">CCCD/CMND</label><input class="form-control" name="identity_no" value="<?= e($profile['identity_no'] ?? '') ?>" <?= $canEditProfile ? '' : 'readonly' ?>></div>
                            <div class="col-md-6"><label class="form-label">Ngày cấp</label><input class="form-control" type="date" name="identity_issue_date" value="<?= e($profile['identity_issue_date'] ?? '') ?>" <?= $canEditProfile ? '' : 'readonly' ?>></div>
                            <div class="col-12"><label class="form-label">Nơi cấp</label><input class="form-control" name="identity_issue_place" value="<?= e($profile['identity_issue_place'] ?? '') ?>" <?= $canEditProfile ? '' : 'readonly' ?>></div>
                            <div class="col-md-4"><label class="form-label">Tài khoản NH</label><input class="form-control" name="bank_account" value="<?= e($profile['bank_account'] ?? '') ?>" <?= $canEditProfile ? '' : 'readonly' ?>></div>
                            <div class="col-md-4"><label class="form-label">Tên ngân hàng</label><input class="form-control" name="bank_name" value="<?= e($profile['bank_name'] ?? '') ?>" <?= $canEditProfile ? '' : 'readonly' ?>></div>
                            <div class="col-md-4"><label class="form-label">Chi nhánh</label><input class="form-control" name="bank_branch" value="<?= e($profile['bank_branch'] ?? '') ?>" <?= $canEditProfile ? '' : 'readonly' ?>></div>
                            <div class="col-md-4"><label class="form-label">Mã số thuế</label><input class="form-control" name="personal_tax_code" value="<?= e($profile['personal_tax_code'] ?? '') ?>" <?= $canEditProfile ? '' : 'readonly' ?>></div>
                            <div class="col-md-4"><label class="form-label">Sổ BHXH</label><input class="form-control" name="social_book_no" value="<?= e($profile['social_book_no'] ?? '') ?>" <?= $canEditProfile ? '' : 'readonly' ?>></div>
                            <div class="col-md-4"><label class="form-label">Số người phụ thuộc</label><input class="form-control" type="number" name="dependants" min="0" value="<?= e($profile['dependants'] ?? 0) ?>" <?= $canEditProfile ? '' : 'readonly' ?>></div>
                            <div class="col-md-4"><div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" name="has_social_insurance" <?= (int) ($profile['has_social_insurance'] ?? 0) === 1 ? 'checked' : '' ?> <?= $canEditProfile ? '' : 'disabled' ?>><label class="form-check-label">Có BHXH</label></div></div>
                            <div class="col-md-4"><label class="form-label">Từ ngày BHXH</label><input class="form-control" type="date" name="insurance_from" value="<?= e($profile['insurance_from'] ?? '') ?>" <?= $canEditProfile ? '' : 'readonly' ?>></div>
                            <div class="col-md-4"><label class="form-label">Ngày vào làm</label><input class="form-control" type="date" name="date_joined" value="<?= e($profile['date_joined'] ?? '') ?>" <?= $canEditProfile ? '' : 'readonly' ?>></div>
                            <div class="col-md-4"><label class="form-label">Ngày phép năm</label><input class="form-control" type="number" step="0.5" name="annual_leave_total" value="<?= e($profile['annual_leave_total'] ?? '') ?>" <?= $canEditProfile ? '' : 'readonly' ?>></div>
                            <div class="col-md-8"><label class="form-label">Tỉnh/Thành thường trú</label><select class="form-select" name="permanent_province" <?= $canEditProfile ? '' : 'disabled' ?>><option value="">Chọn tỉnh/thành</option><?php foreach ($provinces as $province): ?><option value="<?= e($province['code']) ?>" <?= ($profile['permanent_province'] ?? '') === $province['code'] ? 'selected' : '' ?>><?= e($province['name']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6"><label class="form-label">Quận/Huyện</label><input class="form-control" name="permanent_district_text" value="<?= e($profile['permanent_district_text'] ?? '') ?>" <?= $canEditProfile ? '' : 'readonly' ?>></div>
                            <div class="col-md-6"><label class="form-label">Phường/Xã</label><input class="form-control" name="permanent_commune_text" value="<?= e($profile['permanent_commune_text'] ?? '') ?>" <?= $canEditProfile ? '' : 'readonly' ?>></div>
                            <?php if ($canEditProfile): ?><div class="col-12 d-grid"><button class="btn btn-primary" type="submit">Lưu hồ sơ mở rộng</button></div><?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
