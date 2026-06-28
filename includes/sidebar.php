<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/functions.php';

requireLogin();

$currentPath = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '';

$menuSections = [
    [
        'title' => null,
        'roles' => [],
        'items' => [
            ['label' => '🏠 Tổng quan', 'href' => '/ntn_erp/dashboard.php'],
        ],
    ],
    [
        'title' => 'CÁ NHÂN',
        'roles' => [],
        'items' => [
            ['label' => '👤 Hồ sơ', 'href' => '/ntn_erp/modules/users/profile.php'],
            ['label' => '🔑 Đổi mật khẩu', 'href' => '/ntn_erp/modules/users/change_password.php'],
        ],
    ],
    [
        'title' => 'NHÂN SỰ',
        'roles' => [],
        'items' => [
            ['label' => '📅 Chấm công', 'href' => '/ntn_erp/modules/attendance/index.php'],
            ['label' => '📊 Bảng chấm công', 'href' => '/ntn_erp/modules/attendance/all_attendance.php', 'roles' => ['director', 'accountant', 'manager', 'production']],
            ['label' => '📝 Xin nghỉ phép', 'href' => '/ntn_erp/modules/attendance/leave_request.php'],
            ['label' => '✅ Duyệt nghỉ phép', 'href' => '/ntn_erp/modules/attendance/leave_manage.php', 'roles' => ['director', 'manager', 'production']],
            ['label' => '⏱️ Đăng ký OT', 'href' => '/ntn_erp/modules/attendance/ot_request.php'],
            ['label' => '🕐 Duyệt OT', 'href' => '/ntn_erp/modules/attendance/ot_manage.php', 'roles' => ['director', 'manager', 'production']],
            ['label' => '📆 Lịch ca', 'href' => '/ntn_erp/modules/attendance/shift_schedule.php', 'roles' => ['director', 'manager', 'production']],
            ['label' => '👥 Phân công ca', 'href' => '/ntn_erp/modules/attendance/shift_assign.php', 'roles' => ['director', 'manager']],
            ['label' => '⚙️ Setup ca', 'href' => '/ntn_erp/modules/attendance/shift_setup.php', 'roles' => ['director', 'accountant', 'manager']],
            ['label' => '💰 Bảng lương', 'href' => '/ntn_erp/modules/payroll/index.php', 'roles' => ['director', 'accountant']],
            ['label' => '📄 Phiếu lương', 'href' => '/ntn_erp/modules/payroll/my_payroll.php'],
            ['label' => '📅 Ngày nghỉ lễ', 'href' => '/ntn_erp/modules/payroll/holidays.php', 'roles' => ['director', 'accountant']],
        ],
    ],
    [
        'title' => 'SẢN XUẤT',
        'roles' => ['director', 'accountant', 'manager', 'production', 'warehouse'],
        'items' => [
            ['label' => '📦 Nhập kho', 'href' => '/ntn_erp/modules/production/warehouse_import.php'],
            ['label' => '🔧 Phiếu SX', 'href' => '/ntn_erp/modules/production/receipt.php'],
            ['label' => '📤 Xuất thành phẩm', 'href' => '/ntn_erp/modules/production/output.php'],
            ['label' => '🚚 Giao hàng', 'href' => '/ntn_erp/modules/production/delivery.php'],
            ['label' => '🧾 Hóa đơn', 'href' => '/ntn_erp/modules/production/invoice.php'],
            ['label' => '💳 Công nợ', 'href' => '/ntn_erp/modules/production/debt.php'],
        ],
    ],
    [
        'title' => 'DANH MỤC',
        'roles' => [],
        'items' => [
            ['label' => '👥 Khách hàng', 'href' => '/ntn_erp/modules/master/customers.php', 'roles' => ['director', 'accountant', 'manager']],
            ['label' => '📦 Mã sản phẩm', 'href' => '/ntn_erp/modules/master/product_codes.php', 'roles' => ['director', 'manager']],
            ['label' => '💹 Bảng giá', 'href' => '/ntn_erp/modules/master/prices.php', 'roles' => ['director', 'accountant', 'manager']],
        ],
    ],
    [
        'title' => 'NHÂN SỰ QUẢN TRỊ',
        'roles' => ['director', 'accountant'],
        'items' => [
            ['label' => '👥 Danh sách NV', 'href' => '/ntn_erp/modules/users/index.php'],
            ['label' => '➕ Thêm NV', 'href' => '/ntn_erp/modules/users/create.php'],
            ['label' => '📊 KPI', 'href' => '/ntn_erp/modules/kpi/assign.php'],
        ],
    ],
];

$canSee = static function (array $roles): bool {
    return $roles === [] || hasRole(...$roles);
};
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-inner">
        <?php foreach ($menuSections as $section): ?>
            <?php if (!$canSee($section['roles'])) { continue; } ?>
            <?php
            $visibleItems = array_filter($section['items'], static function (array $item) use ($canSee): bool {
                return $canSee($item['roles'] ?? []);
            });
            if (!$visibleItems) { continue; }
            ?>
            <?php if (!empty($section['title'])): ?>
                <div class="sidebar-section-title"><?= e($section['title']) ?></div>
            <?php endif; ?>
            <ul class="nav flex-column sidebar-menu mb-2">
                <?php foreach ($visibleItems as $item): ?>
                    <?php $isActive = $currentPath === $item['href'] || str_starts_with($currentPath . '/', rtrim($item['href'], '/') . '/'); ?>
                    <li class="nav-item">
                        <a class="nav-link<?= $isActive ? ' active' : '' ?>" href="<?= e($item['href']) ?>">
                            <span><?= e($item['label']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </div>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<main class="main-content" id="mainContent">
