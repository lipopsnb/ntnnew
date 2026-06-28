<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function currentUser(): ?array
{
    if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
        $user = $_SESSION['user'];
        return [
            'id' => $user['id'] ?? $user['user_id'] ?? null,
            'name' => $user['full_name'] ?? $user['name'] ?? $user['username'] ?? 'Người dùng',
            'role' => $user['role'] ?? null,
            'department_id' => $user['department_id'] ?? null,
            'employee_code' => $user['employee_code'] ?? null,
        ];
    }

    if (!empty($_SESSION['user_id'])) {
        return [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['full_name'] ?? $_SESSION['name'] ?? $_SESSION['username'] ?? 'Người dùng',
            'role' => $_SESSION['role'] ?? null,
            'department_id' => $_SESSION['department_id'] ?? null,
            'employee_code' => $_SESSION['employee_code'] ?? null,
        ];
    }

    return null;
}

function currentUserId(): int|string|null
{
    return currentUser()['id'] ?? null;
}

function isLoggedIn(): bool
{
    return currentUserId() !== null;
}

function requireLogin(): void
{
    if (isLoggedIn()) {
        return;
    }

    if (function_exists('setFlash')) {
        setFlash('warning', 'Vui lòng đăng nhập để tiếp tục.');
    }

    if (function_exists('redirect')) {
        redirect('login.php');
    }

    header('Location: /ntn_erp/login.php');
    exit;
}

function requireRole(array $roles): void
{
    requireLogin();

    $role = (string) (currentUser()['role'] ?? '');
    if (in_array($role, $roles, true)) {
        return;
    }

    if (function_exists('setFlash') && function_exists('redirect')) {
        setFlash('danger', 'Bạn không có quyền truy cập chức năng này.');
        redirect('dashboard.php');
    }

    http_response_code(403);
    echo '403 Forbidden';
    exit;
}
