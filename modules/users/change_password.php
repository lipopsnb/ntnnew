<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';

requireLogin();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensurePostCsrf();

    $oldPassword = (string) ($_POST['old_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
    $statement->execute(['id' => (int) $_SESSION['user_id']]);
    $user = $statement->fetch();

    if (!$user || !password_verify($oldPassword, (string) $user['password_hash'])) { $errors[] = 'Mật khẩu hiện tại không chính xác.'; }
    if (strlen($newPassword) < 8) { $errors[] = 'Mật khẩu mới phải có ít nhất 8 ký tự.'; }
    if ($newPassword === $oldPassword) { $errors[] = 'Mật khẩu mới phải khác mật khẩu hiện tại.'; }
    if ($newPassword !== $confirmPassword) { $errors[] = 'Xác nhận mật khẩu mới không khớp.'; }

    if ($errors === []) {
        $updateStatement = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $updateStatement->execute(['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => (int) $_SESSION['user_id']]);
        setFlash('success', 'Đổi mật khẩu thành công. Vui lòng sử dụng mật khẩu mới ở lần đăng nhập tiếp theo.');
        redirect('modules/users/change_password.php');
    }
}

$pageTitle = 'Đổi mật khẩu';
$breadcrumbs = [['label' => 'Tổng quan', 'url' => 'dashboard.php'], ['label' => 'Đổi mật khẩu']];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<div class="row justify-content-center"><div class="col-lg-6"><div class="card content-card"><div class="card-body p-4"><h1 class="h4 mb-4">Đổi mật khẩu</h1><?php if ($errors !== []): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?><form method="post" class="needs-validation" novalidate><?= csrf_input() ?><div class="mb-3"><label class="form-label">Mật khẩu hiện tại</label><input type="password" name="old_password" class="form-control" required></div><div class="mb-3"><label class="form-label">Mật khẩu mới</label><input type="password" name="new_password" class="form-control" minlength="8" required></div><div class="mb-4"><label class="form-label">Xác nhận mật khẩu mới</label><input type="password" name="confirm_password" class="form-control" minlength="8" required></div><div class="d-flex justify-content-end gap-2"><a href="<?= e(basePath('dashboard.php')) ?>" class="btn btn-outline-secondary">Quay lại</a><button type="submit" class="btn btn-primary">Cập nhật mật khẩu</button></div></form></div></div></div></div>
<script> (() => { 'use strict'; const forms = document.querySelectorAll('.needs-validation'); Array.from(forms).forEach(form => { form.addEventListener('submit', event => { if (!form.checkValidity()) { event.preventDefault(); event.stopPropagation(); } form.classList.add('was-validated'); }, false); }); })(); </script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
