<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';

requireRole(['director', 'accountant', 'manager']);

$roles = fetchAllSafe($pdo, 'SELECT id, name, display_name FROM roles ORDER BY display_name ASC');
$departments = fetchAllSafe($pdo, 'SELECT id, name FROM departments ORDER BY name ASC');
$generatedCode = generateEmployeeCode($pdo);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensurePostCsrf();

    $employeeCode = trim((string) ($_POST['employee_code'] ?? $generatedCode));
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

    if ($employeeCode === '') { $errors[] = 'Mã nhân viên không được để trống.'; }
    if ($fullName === '') { $errors[] = 'Họ tên không được để trống.'; }
    if ($username === '') { $errors[] = 'Tên đăng nhập không được để trống.'; }
    if (strlen($password) < 8) { $errors[] = 'Mật khẩu phải có ít nhất 8 ký tự.'; }
    if ($roleId <= 0) { $errors[] = 'Vui lòng chọn chức vụ.'; }
    if ($departmentId <= 0) { $errors[] = 'Vui lòng chọn phòng ban.'; }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Email không đúng định dạng.'; }

    $existsStatement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username OR employee_code = :employee_code');
    $existsStatement->execute(['username' => $username, 'employee_code' => $employeeCode]);
    if ((int) $existsStatement->fetchColumn() > 0) { $errors[] = 'Tên đăng nhập hoặc mã nhân viên đã tồn tại.'; }

    if ($errors === []) {
        $insertStatement = $pdo->prepare('INSERT INTO users (employee_code, full_name, username, password_hash, role_id, department_id, phone, email, hire_date, salary_base, bank_account, bank_name, is_active) VALUES (:employee_code, :full_name, :username, :password_hash, :role_id, :department_id, :phone, :email, :hire_date, :salary_base, :bank_account, :bank_name, :is_active)');
        $insertStatement->execute([
            'employee_code' => $employeeCode,
            'full_name' => $fullName,
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role_id' => $roleId,
            'department_id' => $departmentId,
            'phone' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
            'hire_date' => $hireDate !== '' ? $hireDate : null,
            'salary_base' => $salaryBase,
            'bank_account' => $bankAccount !== '' ? $bankAccount : null,
            'bank_name' => $bankName !== '' ? $bankName : null,
            'is_active' => 1,
        ]);
        setFlash('success', 'Thêm nhân viên mới thành công.');
        redirect('modules/users/index.php');
    }
}

$pageTitle = 'Thêm nhân viên';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => 'dashboard.php'],
    ['label' => 'Nhân sự', 'url' => 'modules/users/index.php'],
    ['label' => 'Danh sách', 'url' => 'modules/users/index.php'],
    ['label' => 'Thêm mới'],
];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<div class="card content-card"><div class="card-body p-4"><h1 class="h4 mb-4">Thêm nhân viên mới</h1><?php if ($errors !== []): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?><form method="post" class="needs-validation" novalidate><?= csrf_input() ?><div class="row g-3"><div class="col-md-4"><label class="form-label">Mã nhân viên</label><input type="text" name="employee_code" class="form-control" value="<?= e((string) old('employee_code', $generatedCode)) ?>" required></div><div class="col-md-4"><label class="form-label">Họ tên</label><input type="text" name="full_name" class="form-control" value="<?= e((string) old('full_name')) ?>" required></div><div class="col-md-4"><label class="form-label">Tên đăng nhập</label><input type="text" name="username" class="form-control" value="<?= e((string) old('username')) ?>" required></div><div class="col-md-4"><label class="form-label">Mật khẩu</label><input type="password" name="password" class="form-control" minlength="8" required></div><div class="col-md-4"><label class="form-label">Chức vụ</label><select name="role_id" class="form-select" required><option value="">Chọn chức vụ</option><?php foreach ($roles as $role): ?><option value="<?= e((string) $role['id']) ?>" <?= (string) old('role_id') === (string) $role['id'] ? 'selected' : '' ?>><?= e($role['display_name']) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">Phòng ban</label><select name="department_id" class="form-select" required><option value="">Chọn phòng ban</option><?php foreach ($departments as $department): ?><option value="<?= e((string) $department['id']) ?>" <?= (string) old('department_id') === (string) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">Số điện thoại</label><input type="text" name="phone" class="form-control" value="<?= e((string) old('phone')) ?>"></div><div class="col-md-4"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e((string) old('email')) ?>"></div><div class="col-md-4"><label class="form-label">Ngày vào làm</label><input type="date" name="hire_date" class="form-control" value="<?= e((string) old('hire_date')) ?>"></div><div class="col-md-4"><label class="form-label">Lương cơ bản</label><input type="number" step="1000" min="0" name="salary_base" class="form-control" value="<?= e((string) old('salary_base')) ?>"></div><div class="col-md-4"><label class="form-label">Số tài khoản</label><input type="text" name="bank_account" class="form-control" value="<?= e((string) old('bank_account')) ?>"></div><div class="col-md-4"><label class="form-label">Ngân hàng</label><input type="text" name="bank_name" class="form-control" value="<?= e((string) old('bank_name')) ?>"></div></div><div class="d-flex justify-content-end gap-2 mt-4"><a href="<?= e(basePath('modules/users/index.php')) ?>" class="btn btn-outline-secondary">Hủy</a><button type="submit" class="btn btn-primary">Lưu nhân viên</button></div></form></div></div>
<script> (() => { 'use strict'; const forms = document.querySelectorAll('.needs-validation'); Array.from(forms).forEach(form => { form.addEventListener('submit', event => { if (!form.checkValidity()) { event.preventDefault(); event.stopPropagation(); } form.classList.add('was-validated'); }, false); }); })(); </script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
