<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensurePostCsrf();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '') {
        $errors[] = 'Vui lòng nhập tên đăng nhập.';
    }

    if ($password === '') {
        $errors[] = 'Vui lòng nhập mật khẩu.';
    }

    if ($errors === []) {
        $sql = 'SELECT u.id, u.username, u.full_name, u.password_hash, u.department_id, u.employee_code, u.is_active, r.name AS role_name
                FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                WHERE u.username = :username
                LIMIT 1';
        $statement = $pdo->prepare($sql);
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();

        if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            $errors[] = 'Tên đăng nhập hoặc mật khẩu không chính xác.';
        } elseif ((int) ($user['is_active'] ?? 0) !== 1) {
            $errors[] = 'Tài khoản của bạn đang bị vô hiệu hóa. Vui lòng liên hệ quản trị hệ thống.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role_name'];
            $_SESSION['department_id'] = $user['department_id'];
            $_SESSION['employee_code'] = $user['employee_code'];

            setFlash('success', 'Đăng nhập thành công. Chào mừng bạn quay trở lại!');
            redirect('dashboard.php');
        }
    }
}

$flashMessages = getFlashMessages();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập | NTN ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        body { min-height: 100vh; background: linear-gradient(135deg, #091c35 0%, #0f4c81 55%, #1b6ca8 100%); display: flex; align-items: center; justify-content: center; padding: 1.5rem; }
        .login-card { width: 100%; max-width: 460px; border: 0; border-radius: 1.25rem; box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.28); overflow: hidden; }
        .brand-gradient { background: linear-gradient(135deg, rgba(15, 76, 129, 0.12), rgba(9, 28, 53, 0.02)); }
        .form-control, .input-group-text, .btn { border-radius: .85rem; }
    </style>
</head>
<body>
    <div class="card login-card">
        <div class="card-body p-4 p-lg-5 brand-gradient">
            <div class="text-center mb-4">
                <div class="display-6 fw-bold mb-2">🏭 NTN ERP</div>
                <p class="text-muted mb-0">CÔNG TY CỔ PHẦN SẢN XUẤT VÀ CUNG ỨNG NTN VIỆT NAM</p>
            </div>

            <?php foreach ($flashMessages as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
                    <?= e($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endforeach; ?>

            <?php if ($errors !== []): ?>
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $error): ?>
                            <li><?= e($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" novalidate>
                <?= csrf_input() ?>
                <div class="mb-3">
                    <label for="username" class="form-label fw-semibold">Tên đăng nhập</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-regular fa-user"></i></span>
                        <input type="text" class="form-control" id="username" name="username" value="<?= e((string) old('username')) ?>" placeholder="Nhập tên đăng nhập" autocomplete="username" required>
                    </div>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label fw-semibold">Mật khẩu</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" placeholder="Nhập mật khẩu" autocomplete="current-password" required>
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword" aria-label="Hiện hoặc ẩn mật khẩu">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                    <i class="fa-solid fa-right-to-bracket me-2"></i>Đăng nhập hệ thống
                </button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        togglePassword?.addEventListener('click', () => {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            togglePassword.innerHTML = isPassword
                ? '<i class="fa-regular fa-eye-slash"></i>'
                : '<i class="fa-regular fa-eye"></i>';
        });
    </script>
</body>
</html>
