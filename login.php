<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';

if (isLoggedIn()) {
    redirect('/ntn_erp/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc đã hết hạn. Vui lòng thử lại.');
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            setFlash('danger', 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu.');
        } else {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare('SELECT u.*, r.name as role, r.display_name as role_name FROM users u JOIN roles r ON u.role_id=r.id WHERE u.username=? AND u.is_active=1 LIMIT 1');
            $stmt->execute([$username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && password_verify($password, $row['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['full_name'] = $row['full_name'];
                $_SESSION['role'] = $row['role'];
                $_SESSION['role_name'] = $row['role_name'];
                $_SESSION['employee_code'] = $row['employee_code'];
                $_SESSION['department_id'] = $row['department_id'];

                setFlash('success', 'Đăng nhập thành công.');
                redirect('/ntn_erp/dashboard.php');
            }

            setFlash('danger', 'Tên đăng nhập hoặc mật khẩu không đúng.');
        }
    }
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đăng nhập - NTN ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f172a 0%, #1d4ed8 50%, #38bdf8 100%);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-card {
            width: min(440px, calc(100vw - 2rem));
            border: 0;
            border-radius: 1.5rem;
            box-shadow: 0 25px 60px rgba(15, 23, 42, 0.25);
            overflow: hidden;
        }
        .login-header {
            background: linear-gradient(135deg, #0f172a, #1e3a8a);
            color: #fff;
            text-align: center;
            padding: 2rem 1.5rem 1.5rem;
        }
        .login-logo {
            width: 88px;
            height: 88px;
            margin: 0 auto 1rem;
            border-radius: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,0.12);
            font-size: 2rem;
        }
    </style>
</head>
<body>
    <div class="card login-card">
        <div class="login-header">
            <div class="login-logo">🏭</div>
            <h2 class="h4 mb-2">NTN ERP</h2>
            <p class="mb-0 small">CÔNG TY CỔ PHẦN SẢN XUẤT VÀ CUNG ỨNG NTN VIỆT NAM</p>
        </div>
        <div class="card-body p-4 p-md-5 bg-white">
            <?php showFlash(); ?>
            <form method="post" novalidate>
                <?= csrf_input() ?>
                <div class="mb-3">
                    <label for="username" class="form-label fw-semibold">Tên đăng nhập</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-regular fa-user"></i></span>
                        <input type="text" class="form-control" id="username" name="username" value="<?= e(old('username')) ?>" autocomplete="username" required>
                    </div>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label fw-semibold">Mật khẩu</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                    <i class="fa-solid fa-right-to-bracket me-2"></i>Đăng nhập
                </button>
            </form>
            <div class="text-center text-muted small mt-4">Tài khoản mẫu: <strong>giamdoc</strong> / <strong>123456</strong></div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
