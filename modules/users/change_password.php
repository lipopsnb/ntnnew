<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireLogin();

$pdo = getDBConnection();
$user = currentUser();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc không hợp lệ.');
        header('Location: /ntn_erp/modules/users/change_password.php');
        exit();
    }

    $oldPassword = $_POST['old_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $user['id']]);
    $hash = $stmt->fetchColumn();

    if (!$hash || !password_verify($oldPassword, $hash)) $errors[] = 'Mật khẩu hiện tại không đúng.';
    if (strlen($newPassword) < 6) $errors[] = 'Mật khẩu mới phải có ít nhất 6 ký tự.';
    if ($newPassword !== $confirmPassword) $errors[] = 'Xác nhận mật khẩu không khớp.';

    if (!$errors) {
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $user['id']]);
        setFlash('success', 'Đã đổi mật khẩu thành công.');
        header('Location: /ntn_erp/modules/users/change_password.php');
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
        <div class="card shadow-sm border-0" style="max-width: 520px;">
            <div class="card-header bg-primary text-white">Đổi mật khẩu</div>
            <div class="card-body">
                <form method="post" class="row g-3">
                    <?= csrf_input() ?>
                    <div class="col-12"><label class="form-label">Mật khẩu hiện tại</label><input class="form-control" type="password" name="old_password" required></div>
                    <div class="col-12"><label class="form-label">Mật khẩu mới</label><input class="form-control" type="password" name="new_password" minlength="6" required></div>
                    <div class="col-12"><label class="form-label">Xác nhận mật khẩu mới</label><input class="form-control" type="password" name="confirm_password" minlength="6" required></div>
                    <div class="col-12 d-grid"><button class="btn btn-primary" type="submit">Cập nhật mật khẩu</button></div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
