<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/functions.php';

requireLogin();

$pdo = getDBConnection();
$user = currentUser();
$badge = getRoleBadge($user['role'] ?? '');
$pageTitle = $pageTitle ?? 'NTN ERP';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = ?');
$countStmt->execute([$user['user_id'], 0]);
$unreadNotificationCount = (int) $countStmt->fetchColumn();

$listStmt = $pdo->prepare('SELECT id, title, message, type, reference_id, created_at FROM notifications WHERE user_id = ? AND is_read = ? ORDER BY created_at DESC LIMIT 5');
$listStmt->execute([$user['user_id'], 0]);
$headerNotifications = $listStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="csrf-token" content="<?= e(generateCSRF()) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="/ntn_erp/assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark navbar-ntn sticky-top shadow-sm">
    <div class="container-fluid">
        <button class="btn btn-outline-light btn-sm me-2 d-lg-inline-flex" id="sidebarToggle" type="button" aria-label="Thu gọn menu">
            <i class="fa-solid fa-bars"></i>
        </button>
        <a class="navbar-brand fw-semibold" href="/ntn_erp/dashboard.php">🏭 NTN ERP</a>

        <div class="ms-auto d-flex align-items-center gap-3">
            <div class="dropdown">
                <button class="btn btn-outline-light position-relative" type="button" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unreadNotificationCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?= $unreadNotificationCount ?>
                        </span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-sm notification-dropdown" aria-labelledby="notificationDropdown">
                    <div class="dropdown-header d-flex justify-content-between align-items-center">
                        <span>Thông báo chưa đọc</span>
                        <span class="badge bg-danger"><?= $unreadNotificationCount ?></span>
                    </div>
                    <?php if ($headerNotifications): ?>
                        <?php foreach ($headerNotifications as $notification): ?>
                            <a class="dropdown-item notification-item" href="/ntn_erp/dashboard.php">
                                <div class="fw-semibold small"><?= e($notification['title']) ?></div>
                                <div class="text-muted small"><?= e($notification['message']) ?></div>
                                <div class="text-muted x-small mt-1"><?= e(formatDateTime($notification['created_at'])) ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="dropdown-item text-muted small">Không có thông báo mới.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="dropdown">
                <button class="btn btn-outline-light dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="text-start d-none d-md-inline-block">
                        <span class="d-block fw-semibold"><?= e($user['full_name']) ?></span>
                        <span class="small opacity-75"><?= e($user['employee_code']) ?></span>
                    </span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li class="px-3 py-2 border-bottom">
                        <div class="fw-semibold"><?= e($user['full_name']) ?></div>
                        <span class="badge bg-<?= e($badge['class']) ?> mt-1">
                            <i class="<?= e($badge['icon']) ?> me-1"></i><?= e($badge['label']) ?>
                        </span>
                    </li>
                    <li><a class="dropdown-item" href="/ntn_erp/modules/users/profile.php"><i class="fa-regular fa-user me-2"></i>Hồ sơ</a></li>
                    <li><a class="dropdown-item" href="/ntn_erp/modules/users/change_password.php"><i class="fa-solid fa-key me-2"></i>Đổi mật khẩu</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="/ntn_erp/logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Đăng xuất</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>
