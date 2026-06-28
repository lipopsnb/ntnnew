<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireRole('director', 'accountant');

$pdo = getDBConnection();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$target = $stmt->fetch();
if (!$target) {
    setFlash('danger', 'Không tìm thấy nhân viên.');
    header('Location: /ntn_erp/modules/users/index.php');
    exit();
}

$roles = $pdo->query("SELECT id, display_name FROM roles ORDER BY id ASC")->fetchAll();
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name ASC")->fetchAll();
$errors = [];
$form = $target;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc không hợp lệ.');
        header('Location: /ntn_erp/modules/users/edit.php?id=' . $id);
        exit();
    }

    $form = [
        'employee_code' => trim($_POST['employee_code'] ?? ''),
        'full_name' => trim($_POST['full_name'] ?? ''),
        'username' => trim($_POST['username'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'role_id' => (int) ($_POST['role_id'] ?? 0),
        'department_id' => $_POST['department_id'] !== '' ? (int) $_POST['department_id'] : null,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];
    $password = trim($_POST['password'] ?? '');

    if ($form['employee_code'] === '' || $form['full_name'] === '' || $form['username'] === '' || $form['role_id'] <= 0) {
        $errors[] = 'Vui lòng nhập đầy đủ các trường bắt buộc.';
    }
    if ($password !== '' && strlen($password) < 6) $errors[] = 'Mật khẩu mới phải có ít nhất 6 ký tự.';

    $checkStmt = $pdo->prepare("SELECT SUM(username = ?) AS username_exists, SUM(employee_code = ?) AS code_exists FROM users WHERE id <> ?");
    $checkStmt->execute([$form['username'], $form['employee_code'], $id]);
    $check = $checkStmt->fetch();
    if ((int) ($check['username_exists'] ?? 0) > 0) $errors[] = 'Tên đăng nhập đã tồn tại.';
    if ((int) ($check['code_exists'] ?? 0) > 0) $errors[] = 'Mã nhân viên đã tồn tại.';

    if (!$errors) {
        if ($password !== '') {
            $stmt = $pdo->prepare("UPDATE users SET employee_code = ?, full_name = ?, username = ?, password_hash = ?, email = ?, phone = ?, role_id = ?, department_id = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$form['employee_code'], $form['full_name'], $form['username'], password_hash($password, PASSWORD_DEFAULT), $form['email'] ?: null, $form['phone'] ?: null, $form['role_id'], $form['department_id'], $form['is_active'], $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET employee_code = ?, full_name = ?, username = ?, email = ?, phone = ?, role_id = ?, department_id = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$form['employee_code'], $form['full_name'], $form['username'], $form['email'] ?: null, $form['phone'] ?: null, $form['role_id'], $form['department_id'], $form['is_active'], $id]);
        }
        setFlash('success', 'Đã cập nhật nhân viên.');
        header('Location: /ntn_erp/modules/users/index.php');
        exit();
    }
}

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="main-content">
    <div class="container-fluid py-4">
        <?php showFlash(); ?>
        <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-primary text-white">Chỉnh sửa nhân viên</div>
            <div class="card-body">
                <form method="post" class="row g-3">
                    <?= csrf_input() ?>
                    <div class="col-md-4"><label class="form-label">Mã nhân viên</label><input class="form-control" name="employee_code" value="<?= e($form['employee_code']) ?>" required></div>
                    <div class="col-md-8"><label class="form-label">Họ tên</label><input class="form-control" name="full_name" value="<?= e($form['full_name']) ?>" required></div>
                    <div class="col-md-4"><label class="form-label">Tên đăng nhập</label><input class="form-control" name="username" value="<?= e($form['username']) ?>" required></div>
                    <div class="col-md-4"><label class="form-label">Mật khẩu mới</label><input class="form-control" type="password" name="password" placeholder="Để trống nếu không đổi"></div>
                    <div class="col-md-4 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="is_active" <?= (int) $form['is_active'] === 1 ? 'checked' : '' ?>><label class="form-check-label">Hoạt động</label></div></div>
                    <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" value="<?= e($form['email'] ?? '') ?>"></div>
                    <div class="col-md-6"><label class="form-label">Điện thoại</label><input class="form-control" name="phone" value="<?= e($form['phone'] ?? '') ?>"></div>
                    <div class="col-md-6"><label class="form-label">Vai trò</label><select class="form-select" name="role_id" required><?php foreach ($roles as $role): ?><option value="<?= (int) $role['id'] ?>" <?= (int) $form['role_id'] === (int) $role['id'] ? 'selected' : '' ?>><?= e($role['display_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label">Phòng ban</label><select class="form-select" name="department_id"><option value="">Chọn phòng ban</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= (string) ($form['department_id'] ?? '') === (string) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit">Lưu thay đổi</button><a class="btn btn-outline-secondary" href="/ntn_erp/modules/users/index.php">Quay lại</a></div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
