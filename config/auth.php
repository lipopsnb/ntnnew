<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function requireLogin(): void
{
    if (isLoggedIn()) {
        return;
    }

    setFlash('danger', 'Vui lòng đăng nhập để tiếp tục.');
    redirect('/ntn_erp/login.php');
}

function hasRole(...$roles): bool
{
    if (!isLoggedIn()) {
        return false;
    }

    if (count($roles) === 1 && is_array($roles[0])) {
        $roles = $roles[0];
    }

    $currentRole = (string) ($_SESSION['role'] ?? '');
    return in_array($currentRole, $roles, true);
}

function requireRole(...$roles): void
{
    requireLogin();

    if (count($roles) === 1 && is_array($roles[0])) {
        $roles = $roles[0];
    }

    if (hasRole(...$roles)) {
        return;
    }

    http_response_code(403);
    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><title>403</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head>';
    echo '<body class="bg-light"><div class="container py-5"><div class="alert alert-danger">';
    echo 'Bạn không có quyền truy cập chức năng này.';
    echo '</div><a class="btn btn-primary" href="/ntn_erp/dashboard.php">Quay lại tổng quan</a></div></body></html>';
    exit;
}

function currentUser(): array
{
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'user_id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'full_name' => $_SESSION['full_name'] ?? null,
        'role' => $_SESSION['role'] ?? null,
        'role_name' => $_SESSION['role_name'] ?? null,
        'employee_code' => $_SESSION['employee_code'] ?? null,
        'department_id' => $_SESSION['department_id'] ?? null,
    ];
}

function getRoleBadge($role): array
{
    $badges = [
        'director' => ['class' => 'danger', 'icon' => 'fa-solid fa-crown', 'label' => 'Giám đốc'],
        'accountant' => ['class' => 'warning text-dark', 'icon' => 'fa-solid fa-wallet', 'label' => 'Kế toán'],
        'manager' => ['class' => 'primary', 'icon' => 'fa-solid fa-user-tie', 'label' => 'Quản lý'],
        'warehouse' => ['class' => 'info text-dark', 'icon' => 'fa-solid fa-warehouse', 'label' => 'Kho'],
        'production' => ['class' => 'success', 'icon' => 'fa-solid fa-industry', 'label' => 'Sản xuất'],
        'employee' => ['class' => 'secondary', 'icon' => 'fa-solid fa-user', 'label' => 'Nhân viên'],
    ];

    return $badges[$role] ?? ['class' => 'secondary', 'icon' => 'fa-solid fa-circle-question', 'label' => 'Không xác định'];
}

function redirect(string $path): void
{
    $location = $path;
    if (!preg_match('#^(https?:)?/#', $path)) {
        $location = '/ntn_erp/' . ltrim($path, '/');
    }

    header('Location: ' . $location);
    exit;
}

function generateCSRF(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function verifyCSRF($token): bool
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    return is_string($token) && $sessionToken !== '' && hash_equals($sessionToken, $token);
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(generateCSRF()) . '">';
}

function setFlash(string $type, string $msg): void
{
    $_SESSION['flash_messages'] ??= [];
    $_SESSION['flash_messages'][] = ['type' => $type, 'message' => $msg];
}

function getFlash(): ?array
{
    $messages = getFlashMessages();
    return $messages[0] ?? null;
}

function getFlashMessages(): array
{
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return is_array($messages) ? $messages : [];
}

function showFlash(): void
{
    foreach (getFlashMessages() as $flash) {
        $type = e((string) ($flash['type'] ?? 'info'));
        $message = e((string) ($flash['message'] ?? ''));
        echo '<div class="alert alert-' . $type . ' alert-dismissible fade show flash-message" role="alert">';
        echo $message;
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Đóng"></button>';
        echo '</div>';
    }
}

function e($str): string
{
    return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
}

function old(string $key)
{
    return $_POST[$key] ?? '';
}
