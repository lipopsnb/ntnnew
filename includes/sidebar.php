<?php
declare(strict_types=1);
$breadcrumbs = $breadcrumbs ?? [];
$flashMessages = getFlashMessages();
$role = (string) (currentUser()['role'] ?? ($_SESSION['role'] ?? 'employee'));
?>
<aside class="col-lg-2 col-md-3 sidebar p-3">
    <div class="text-white small text-uppercase fw-semibold mb-3">Điều hướng</div>
    <nav class="nav flex-column">
        <a class="nav-link <?= activeMenu('dashboard.php') ?>" href="<?= e(basePath('dashboard.php')) ?>"><i class="fa-solid fa-chart-line me-2"></i>Tổng quan</a>
        <?php if (in_array($role, ['director', 'accountant', 'manager'], true)): ?>
            <a class="nav-link <?= activeMenu('/modules/users/') ?>" href="<?= e(basePath('modules/users/index.php')) ?>"><i class="fa-solid fa-users me-2"></i>Nhân sự</a>
        <?php endif; ?>
        <?php if (in_array($role, ['director', 'accountant', 'manager', 'production', 'warehouse'], true)): ?>
            <div class="text-white-50 small text-uppercase fw-semibold mt-4 mb-2">Hành chính</div>
            <?php if (in_array($role, ['director', 'accountant', 'manager'], true)): ?>
                <a class="nav-link <?= activeMenu('/modules/admin/index.php') ?>" href="<?= e(basePath('modules/admin/index.php')) ?>"><i class="fa-solid fa-sitemap me-2"></i>Dashboard HC</a>
            <?php endif; ?>
            <?php if (in_array($role, ['director', 'manager'], true)): ?>
                <a class="nav-link <?= activeMenu('/modules/admin/assets.php') || activeMenu('/modules/admin/asset_create.php') ? 'active' : '' ?>" href="<?= e(basePath('modules/admin/assets.php')) ?>"><i class="fa-solid fa-building-shield me-2"></i>Tài sản</a>
                <a class="nav-link <?= activeMenu('/modules/admin/vehicle_log.php') ?>" href="<?= e(basePath('modules/admin/vehicle_log.php')) ?>"><i class="fa-solid fa-road me-2"></i>Nhật ký xe</a>
                <a class="nav-link <?= activeMenu('/modules/admin/vehicle_expense.php') ?>" href="<?= e(basePath('modules/admin/vehicle_expense.php')) ?>"><i class="fa-solid fa-gas-pump me-2"></i>Chi phí xe</a>
            <?php endif; ?>
            <?php if (in_array($role, ['director', 'manager', 'warehouse'], true)): ?>
                <a class="nav-link <?= activeMenu('/modules/admin/consumable_in.php') ?>" href="<?= e(basePath('modules/admin/consumable_in.php')) ?>"><i class="fa-solid fa-boxes-packing me-2"></i>Nhập vật tư</a>
                <a class="nav-link <?= activeMenu('/modules/admin/consumable_out.php') ?>" href="<?= e(basePath('modules/admin/consumable_out.php')) ?>"><i class="fa-solid fa-box-open me-2"></i>Xuất vật tư</a>
            <?php endif; ?>
            <?php if (in_array($role, ['director', 'manager', 'production'], true)): ?>
                <a class="nav-link <?= activeMenu('/modules/admin/maintenance.php') ?>" href="<?= e(basePath('modules/admin/maintenance.php')) ?>"><i class="fa-solid fa-screwdriver-wrench me-2"></i>Bảo trì</a>
            <?php endif; ?>
            <div class="text-white-50 small text-uppercase fw-semibold mt-4 mb-2">Chi phí</div>
            <a class="nav-link <?= activeMenu('/modules/admin/expense.php') ?>" href="<?= e(basePath('modules/admin/expense.php')) ?>"><i class="fa-solid fa-file-invoice-dollar me-2"></i>Đề xuất chi phí</a>
            <?php if (in_array($role, ['director', 'accountant'], true)): ?>
                <a class="nav-link <?= activeMenu('/modules/admin/expense_approve.php') ?>" href="<?= e(basePath('modules/admin/expense_approve.php')) ?>"><i class="fa-solid fa-circle-check me-2"></i>Duyệt chi phí</a>
            <?php endif; ?>
        <?php endif; ?>
        <div class="text-white-50 small text-uppercase fw-semibold mt-4 mb-2">Tài khoản</div>
        <a class="nav-link <?= activeMenu('profile.php') ?>" href="<?= e(basePath('modules/users/profile.php')) ?>"><i class="fa-regular fa-user me-2"></i>Hồ sơ cá nhân</a>
        <a class="nav-link <?= activeMenu('change_password.php') ?>" href="<?= e(basePath('modules/users/change_password.php')) ?>"><i class="fa-solid fa-key me-2"></i>Đổi mật khẩu</a>
    </nav>
</aside>
<main class="col-lg-10 col-md-9 p-4">
    <?php if ($breadcrumbs !== []): ?>
        <?php renderBreadcrumb($breadcrumbs); ?>
    <?php endif; ?>
    <?php foreach ($flashMessages as $flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= e($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endforeach; ?>
