<?php
declare(strict_types=1);
$pageTitle = $pageTitle ?? 'NTN ERP';
$currentUser = currentUser();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | NTN ERP</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        :root { --erp-primary: #0f4c81; --erp-sidebar: #0d1b2a; --erp-bg: #f4f7fb; }
        body { font-family: 'Inter', sans-serif; background: var(--erp-bg); }
        .topbar { background: linear-gradient(90deg, #0f4c81, #173f6d); }
        .sidebar { min-height: calc(100vh - 56px); background: var(--erp-sidebar); }
        .sidebar .nav-link { color: rgba(255,255,255,.82); border-radius: .65rem; margin-bottom: .35rem; }
        .sidebar .nav-link.active, .sidebar .nav-link:hover { background: rgba(255,255,255,.12); color: #fff; }
        .stat-card { border: none; border-radius: 1rem; box-shadow: 0 .5rem 1.5rem rgba(15, 76, 129, .08); }
        .content-card { border: 0; border-radius: 1rem; box-shadow: 0 .5rem 1.5rem rgba(20, 46, 80, .06); }
        .avatar-circle { width: 72px; height: 72px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.5rem; background: rgba(15,76,129,.12); color: var(--erp-primary); }
        .table thead th { white-space: nowrap; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark topbar shadow-sm sticky-top">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-semibold" href="<?= e(basePath('dashboard.php')) ?>">🏭 NTN ERP</a>
        <div class="d-flex align-items-center gap-3 text-white">
            <div class="text-end d-none d-md-block">
                <div class="fw-semibold"><?= e($currentUser['name'] ?? 'Người dùng') ?></div>
                <small class="text-white-50"><?= e(roleLabel((string) ($currentUser['role'] ?? 'employee'))) ?></small>
            </div>
            <form method="post" action="<?= e(basePath('logout.php')) ?>" class="mb-0">
                <?= csrf_input() ?>
                <button type="submit" class="btn btn-sm btn-outline-light"><i class="fa-solid fa-right-from-bracket me-1"></i>Đăng xuất</button>
            </form>
        </div>
    </div>
</nav>
<div class="container-fluid">
    <div class="row g-0">
