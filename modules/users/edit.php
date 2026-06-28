<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';

requireRole(['director', 'accountant', 'manager']);

$userId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($userId <= 0) {
    setFlash('danger', 'Nhân viên không hợp lệ.');
    redirect('modules/users/index.php');
}

$roles = fetchAllSafe($pdo, 'SELECT id, name, display_name FROM roles ORDER BY display_name ASC');
$departments = fetchAllSafe($pdo, 'SELECT id, name FROM departments ORDER BY name ASC');
$userStatement = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$userStatement->execute(['id' => $userId]);
$user = $userStatement->fetch();

if (!$user) {
    setFlash('danger', 'Không tìm thấy nhân viên cần chỉnh sửa.');
    redirect('modules/users/index.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensurePostCsrf();

    $employeeCode = trim((string) ($_POST['employee_code'] ?? ''));
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $roleId = (int) ($_POST['role_id'] ?? 0);
    $departmentId = (int) ($_POST['department_id'] ?? 0);
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $hireDate = trim((string) ($_POST['hire_date'] ?? ''));
    $salaryBase = (float) ($_POST['salary_base'] ?? 0);
    $bankAccount = trim((string) ($_POST['bank_account'] ?? ''));
    $bankName = trim((string) ($_POST['bank_name'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($employeeCode === '') { $errors[] = 'Mã nhân viên không được để trống.'; }
    if ($fullName === '') { $errors[] = 'Họ tên không được để trống.'; }
    if ($username === '') { $errors[] = 'Tên đăng nhập không được để trống.'; }
    if ($roleId <= 0) { $errors[] = 'Vui lòng chọn chức vụ.'; }
    if ($departmentId <= 0) { $errors[] = 'Vui lòng chọn phòng ban.'; }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Email không đúng định dạng.'; }
    if ($password !== '' && strlen($password) < 8) { $errors[] = 'Mật khẩu mới phải có ít nhất 8 ký tự.'; }

    $existsStatement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE (username = :username OR employee_code = :employee_code) AND id <> :id');
    $existsStatement->execute(['username' => $username, 'employee_code' => $employeeCode, 'id' => $userId]);
    if ((int) $existsStatement->fetchColumn() > 0) { $errors[] = 'Tên đăng nhập hoặc mã nhân viên đã tồn tại.'; }

    if ($errors === []) {
        $sql = 'UPDATE users SET employee_code = :employee_code, full_name = :full_name, username = :username, role_id = :role_id, department_id = :department_id, phone = :phone, email = :email, hire_date = :hire_date, salary_base = :salary_base, bank_account = :bank_account, bank_name = :bank_name, is_active = :is_active';
        $params = [
            'employee_code' => $employeeCode,
            'full_name' => $fullName,
            'username' => $username,
            'role_id' => $roleId,
            'department_id' => $departmentId,
            'phone' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
            'hire_date' => $hireDate !== '' ? $hireDate : null,
            'salary_base' => $salaryBase,
            'bank_account' => $bankAccount !== '' ? $bankAccount : null,
            'bank_name' => $bankName !== '' ? $bankName : null,
            'is_active' => $isActive,
            'id' => $userId,
        ];
        if ($password !== '') {
            $sql .= ', password_hash = :password_hash';
            $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id = :id';
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        setFlash('success', 'Cập nhật nhân viên thành công.');
        redirect('modules/users/index.php');
    }

    $user = array_merge($user, ['employee_code' => $employeeCode, 'full_name' => $fullName, 'username' => $username, 'role_id' => $roleId, 'department_id' => $departmentId, 'phone' => $phone, 'email' => $email, 'hire_date' => $hireDate, 'salary_base' => $salaryBase, 'bank_account' => $bankAccount, 'bank_name' => $bankName, 'is_active' => $isActive]);
}

$pageTitle = 'Chỉnh sửa nhân viên';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => 'dashboard.php'],
    ['label' => 'Nhân sự', 'url' => 'modules/users/index.php'],
    ['label' => 'Danh sách', 'url' => 'modules/users/index.php'],
    ['label' => 'Chỉnh sửa'],
];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<div class="card content-card"><div class="card-body p-4"><h1 class="h4 mb-4">Chỉnh sửa nhân viên</h1><?php if ($errors !== []): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?><form method="post" class="needs-validation" novalidate><?= csrf_input() ?><input type="hidden" name="id" value="<?= e((string) $userId) ?>"><div class="row g-3"><div class="col-md-4"><label class="form-label">Mã nhân viên</label><input type="text" name="employee_code" class="form-control" value="<?= e((string) ($user['employee_code'] ?? '')) ?>" required></div><div class="col-md-4"><label class="form-label">Họ tên</label><input type="text" name="full_name" class="form-control" value="<?= e((string) ($user['full_name'] ?? '')) ?>" required></div><div class="col-md-4"><label class="form-label">Tên đăng nhập</label><input type="text" name="username" class="form-control" value="<?= e((string) ($user['username'] ?? '')) ?>" required></div><div class="col-md-4"><label class="form-label">Mật khẩu mới (để trống nếu giữ nguyên)</label><input type="password" name="password" class="form-control" minlength="8"></div><div class="col-md-4"><label class="form-label">Chức vụ</label><select name="role_id" class="form-select" required><option value="">Chọn chức vụ</option><?php foreach ($roles as $role): ?><option value="<?= e((string) $role['id']) ?>" <?= (string) ($user['role_id'] ?? '') === (string) $role['id'] ? 'selected' : '' ?>><?= e($role['display_name']) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">Phòng ban</label><select name="department_id" class="form-select" required><option value="">Chọn phòng ban</option><?php foreach ($departments as $department): ?><option value="<?= e((string) $department['id']) ?>" <?= (string) ($user['department_id'] ?? '') === (string) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">Số điện thoại</label><input type="text" name="phone" class="form-control" value="<?= e((string) ($user['phone'] ?? '')) ?>"></div><div class="col-md-4"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e((string) ($user['email'] ?? '')) ?>"></div><div class="col-md-4"><label class="form-label">Ngày vào làm</label><input type="date" name="hire_date" class="form-control" value="<?= e((string) ($user['hire_date'] ?? '')) ?>"></div><div class="col-md-4"><label class="form-label">Lương cơ bản</label><input type="number" step="1000" min="0" name="salary_base" class="form-control" value="<?= e((string) ($user['salary_base'] ?? '0')) ?>"></div><div class="col-md-4"><label class="form-label">Số tài khoản</label><input type="text" name="bank_account" class="form-control" value="<?= e((string) ($user['bank_account'] ?? '')) ?>"></div><div class="col-md-4"><label class="form-label">Ngân hàng</label><input type="text" name="bank_name" class="form-control" value="<?= e((string) ($user['bank_name'] ?? '')) ?>"></div><div class="col-12"><div class="form-check form-switch"><input type="checkbox" class="form-check-input" id="is_active" name="is_active" <?= (int) ($user['is_active'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Tài khoản đang hoạt động</label></div></div></div><div class="d-flex justify-content-end gap-2 mt-4"><a href="<?= e(basePath('modules/users/index.php')) ?>" class="btn btn-outline-secondary">Hủy</a><button type="submit" class="btn btn-primary">Cập nhật</button></div></form></div></div>
<script> (() => { 'use strict'; const forms = document.querySelectorAll('.needs-validation'); Array.from(forms).forEach(form => { form.addEventListener('submit', event => { if (!form.checkValidity()) { event.preventDefault(); event.stopPropagation(); } form.classList.add('was-validated'); }, false); }); })(); </script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
